using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;

namespace Bajama.Windows;

internal sealed class ApiClient : IDisposable
{
    private HttpClient _http = new() { Timeout = TimeSpan.FromSeconds(20) };
    private string? _accessToken;
    private string? _refreshToken;
    private DateTimeOffset _accessExpiresAt;

    public void Configure(string baseUrl)
    {
        var value = baseUrl.Trim().TrimEnd('/');
        if (!Uri.TryCreate(value, UriKind.Absolute, out var uri)
            || (uri.Scheme != Uri.UriSchemeHttps && !IsLocalDevelopment(uri)))
        {
            throw new InvalidOperationException("Gunakan alamat HTTPS server BAJAMA. HTTP hanya diizinkan untuk localhost.");
        }

        var baseAddress = new Uri(value + "/", UriKind.Absolute);
        if (_http.BaseAddress is not null && _http.BaseAddress != baseAddress)
        {
            _http.Dispose();
            _http = new HttpClient { Timeout = TimeSpan.FromSeconds(20) };
            _accessToken = null;
            _refreshToken = null;
        }
        if (_http.BaseAddress is null)
        {
            _http.BaseAddress = baseAddress;
        }
    }

    private static bool IsLocalDevelopment(Uri uri) =>
        uri.IsLoopback && uri.Scheme == Uri.UriSchemeHttp;

    public async Task<JsonElement> LoginAsync(string identifier, string password)
    {
        using var response = await _http.PostAsJsonAsync("api/v1/auth/login", new
        {
            identifier,
            password,
            client_name = "BAJAMA Windows"
        });

        using var document = await ReadResponseAsync(response);
        var data = document.RootElement.GetProperty("data");
        ApplyTokens(data);
        return data.Clone();
    }

    public async Task<JsonElement> GetAsync(string endpoint)
    {
        await EnsureFreshTokenAsync();
        using var request = CreateRequest(HttpMethod.Get, endpoint);
        using var response = await _http.SendAsync(request);
        using var document = await ReadResponseAsync(response);
        return document.RootElement.GetProperty("data").Clone();
    }

    public async Task LogoutAsync()
    {
        if (string.IsNullOrWhiteSpace(_refreshToken)) return;
        try
        {
            await EnsureFreshTokenAsync();
            using var request = CreateRequest(HttpMethod.Post, "api/v1/auth/logout");
            using var response = await _http.SendAsync(request);
            response.EnsureSuccessStatusCode();
        }
        finally
        {
            _accessToken = null;
            _refreshToken = null;
        }
    }

    private async Task EnsureFreshTokenAsync()
    {
        if (string.IsNullOrWhiteSpace(_refreshToken))
            throw new InvalidOperationException("Silakan login kembali.");
        if (_accessExpiresAt > DateTimeOffset.UtcNow.AddSeconds(60)) return;

        using var response = await _http.PostAsJsonAsync("api/v1/auth/refresh", new
        {
            refresh_token = _refreshToken
        });
        using var document = await ReadResponseAsync(response);
        ApplyTokens(document.RootElement.GetProperty("data"));
    }

    private void ApplyTokens(JsonElement data)
    {
        _accessToken = data.GetProperty("access_token").GetString();
        _refreshToken = data.GetProperty("refresh_token").GetString();
        var expiresIn = data.TryGetProperty("expires_in", out var expires)
            ? expires.GetInt32()
            : 900;
        _accessExpiresAt = DateTimeOffset.UtcNow.AddSeconds(expiresIn);
        if (string.IsNullOrWhiteSpace(_accessToken) || string.IsNullOrWhiteSpace(_refreshToken))
            throw new InvalidOperationException("Server tidak mengembalikan token akses yang valid.");
    }

    private HttpRequestMessage CreateRequest(HttpMethod method, string endpoint)
    {
        if (_http.BaseAddress is null) throw new InvalidOperationException("Alamat API belum diatur.");
        if (string.IsNullOrWhiteSpace(_accessToken)) throw new InvalidOperationException("Silakan login kembali.");
        var request = new HttpRequestMessage(method, endpoint.TrimStart('/'));
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", _accessToken);
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        return request;
    }

    private static async Task<JsonDocument> ReadResponseAsync(HttpResponseMessage response)
    {
        var payload = await response.Content.ReadAsStringAsync();
        JsonDocument? document = null;
        try
        {
            document = JsonDocument.Parse(payload);
        }
        catch (JsonException)
        {
            throw new InvalidOperationException($"Server mengembalikan respons yang tidak valid (HTTP {(int)response.StatusCode}).");
        }

        if (!response.IsSuccessStatusCode)
        {
            var message = document.RootElement.TryGetProperty("error", out var error)
                && error.TryGetProperty("message", out var detail)
                ? detail.GetString()
                : null;
            document.Dispose();
            throw new InvalidOperationException(message ?? $"Permintaan gagal (HTTP {(int)response.StatusCode}).");
        }
        return document;
    }

    public void Dispose() => _http.Dispose();
}
