using System.Net.Http;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;

namespace Bajama.Windows;

public partial class MainWindow : Window
{
    private readonly ApiClient _api = new();
    private JsonElement _me;

    public MainWindow()
    {
        InitializeComponent();
        Closed += (_, _) => _api.Dispose();
    }

    private async void LoginButton_Click(object sender, RoutedEventArgs e)
    {
        LoginMessage.Text = string.Empty;
        LoginButton.IsEnabled = false;
        LoginButton.Content = "Menghubungkan...";
        try
        {
            _api.Configure(ApiUrlBox.Text);
            await _api.LoginAsync(UsernameBox.Text.Trim(), PasswordBox.Password);
            _me = await _api.GetAsync("api/v1/me");
            PasswordBox.Clear();
            LoginPanel.Visibility = Visibility.Collapsed;
            ShellPanel.Visibility = Visibility.Visible;
            SetIdentity();
            SetConnection(true);
            await LoadPageAsync("home");
        }
        catch (Exception ex)
        {
            LoginMessage.Text = ex.Message;
        }
        finally
        {
            LoginButton.IsEnabled = true;
            LoginButton.Content = "Masuk ke BAJAMA";
        }
    }

    private void SetIdentity()
    {
        var organization = _me.GetProperty("organization");
        var user = _me.GetProperty("user");
        OrganizationLabel.Text = organization.GetProperty("name").GetString() ?? "Organisasi";
        UserLabel.Text = user.GetProperty("full_name").GetString();
    }

    private async void NavigationButton_Click(object sender, RoutedEventArgs e)
    {
        if (sender is Button { Tag: string route }) await LoadPageAsync(route);
    }

    private async Task LoadPageAsync(string route)
    {
        ContentCards.Children.Clear();
        PageMessage.Text = string.Empty;
        PageHeading.Text = route switch
        {
            "billing" => "Billing",
            "routers" => "Router & MikroTik",
            "olts" => "OLT / Fiber",
            _ => "Ringkasan"
        };
        PageTitle.Text = PageHeading.Text;
        PageDescription.Text = route switch
        {
            "billing" => "Ringkasan tagihan organisasi Anda.",
            "routers" => "Status router yang terdaftar di BAJAMA.",
            "olts" => "Inventaris OLT yang tercatat di BAJAMA.",
            _ => "Informasi akun dan ringkasan layanan BAJAMA."
        };

        try
        {
            if (route == "billing")
            {
                var summary = await _api.GetAsync("api/v1/billing/summary");
                AddMetric("Total invoice", Value(summary, "total_invoices"), "Dokumen tagihan");
                AddMetric("Belum lunas", Value(summary, "unpaid_invoices"), "Termasuk invoice lewat jatuh tempo");
                AddMetric("Lewat jatuh tempo", Value(summary, "overdue_invoices"), "Perlu ditindaklanjuti");
                AddMetric("Outstanding", Money(summary, "outstanding_amount"), "Nilai tagihan terbuka");
                AddMetric("Sudah dibayar", Money(summary, "paid_amount"), "Nilai invoice lunas");
                   var invoices = await _api.GetAsync("api/v1/billing/invoices");
                   AddCollection(invoices, "Invoice", new[] { "invoice_number", "customer_name", "status", "due_date", "total" });
            }
            else if (route == "routers")
            {
                var routers = await _api.GetAsync("api/v1/network/routers");
                AddCollection(routers, "Router", new[] { "name", "host", "status" });
            }
            else if (route == "olts")
            {
                var olts = await _api.GetAsync("api/v1/network/olts");
                AddCollection(olts, "OLT", new[] { "name", "vendor", "management_ip", "status", "router_name" });
            }
            else
            {
                var org = _me.GetProperty("organization");
                var license = _me.GetProperty("license");
                AddMetric("Organisasi", org.GetProperty("name").GetString() ?? "-", "Tenant BAJAMA aktif");
                AddMetric("Status lisensi", license.GetProperty("status").GetString() ?? "-", license.GetProperty("plan").GetString() ?? "Paket tidak tersedia");
                AddMetric("Pengguna", _me.GetProperty("user").GetProperty("full_name").GetString() ?? "-", "Akun yang sedang masuk");
                AddMetric("Modul aktif", CountFeatures(license.GetProperty("features")).ToString(), "Fitur sesuai lisensi organisasi");
            }
            SetConnection(true);
        }
        catch (Exception ex)
        {
            SetConnection(false);
            PageMessage.Text = ex.Message;
        }
    }

    private void SetConnection(bool connected)
    {
        ConnectionStatus.Text = connected ? "● Terhubung" : "● Tidak terhubung";
        ConnectionStatus.Foreground = connected
            ? new SolidColorBrush(Color.FromRgb(52, 211, 153))
            : new SolidColorBrush(Color.FromRgb(248, 113, 113));
    }

    private void AddMetric(string title, string value, string caption)
    {
        var card = new Border
        {
            Width = 235,
            MinHeight = 132,
            Margin = new Thickness(0, 0, 14, 14),
            Padding = new Thickness(18),
            CornerRadius = new CornerRadius(16),
            Background = Brushes.White,
            BorderBrush = new SolidColorBrush(Color.FromRgb(230, 235, 243)),
            BorderThickness = new Thickness(1)
        };
        var stack = new StackPanel();
        stack.Children.Add(new TextBlock { Text = title.ToUpperInvariant(), FontSize = 10, FontWeight = FontWeights.Bold, Foreground = new SolidColorBrush(Color.FromRgb(100, 116, 139)) });
        stack.Children.Add(new TextBlock { Text = value, FontSize = 21, FontWeight = FontWeights.Bold, Foreground = new SolidColorBrush(Color.FromRgb(23, 37, 84)), Margin = new Thickness(0, 13, 0, 5), TextWrapping = TextWrapping.Wrap });
        stack.Children.Add(new TextBlock { Text = caption, FontSize = 12, Foreground = new SolidColorBrush(Color.FromRgb(100, 116, 139)), TextWrapping = TextWrapping.Wrap });
        card.Child = stack;
        ContentCards.Children.Add(card);
    }

    private void AddCollection(JsonElement rows, string entity, string[] fields)
    {
        if (rows.ValueKind != JsonValueKind.Array || rows.GetArrayLength() == 0)
        {
            AddMetric("Belum ada data", "0", $"Belum ada {entity} terdaftar atau akses modul tidak tersedia.");
            return;
        }
        foreach (var row in rows.EnumerateArray())
        {
            var titleKey = fields[0];
            var title = row.TryGetProperty(titleKey, out var titleValue) ? titleValue.ToString() : entity;
            var details = fields.Skip(1)
                .Where(key => row.TryGetProperty(key, out var value) && value.ValueKind != JsonValueKind.Null && !string.IsNullOrWhiteSpace(value.ToString()))
                .Select(key => $"{Humanize(key)}: {row.GetProperty(key).ToString()}")
                .ToArray();
            AddMetric(entity, title, details.Length == 0 ? "Data status belum tersedia" : string.Join("  •  ", details));
        }
    }

    private static string Humanize(string value) => string.Join(" ", value.Split('_').Select(part => char.ToUpperInvariant(part[0]) + part[1..]));
    private static string Value(JsonElement element, string key) => element.TryGetProperty(key, out var value) ? value.ToString() : "0";
    private static string Money(JsonElement element, string key)
    {
        if (!element.TryGetProperty(key, out var value)
            || !decimal.TryParse(value.ToString(), System.Globalization.NumberStyles.Number,
                System.Globalization.CultureInfo.InvariantCulture, out var amount)) return "Rp 0";
        return string.Format(System.Globalization.CultureInfo.GetCultureInfo("id-ID"), "Rp {0:N0}", amount);
    }

    private static int CountFeatures(JsonElement features) => features.ValueKind != JsonValueKind.Object
        ? 0
        : features.EnumerateObject().Count(property => property.Value.ValueKind == JsonValueKind.True || property.Value.ToString() == "1");

    private async void LogoutButton_Click(object sender, RoutedEventArgs e)
    {
        try { await _api.LogoutAsync(); }
        catch { /* Token akan kedaluwarsa otomatis jika server tak dapat dijangkau. */ }
        ShellPanel.Visibility = Visibility.Collapsed;
        LoginPanel.Visibility = Visibility.Visible;
        LoginMessage.Text = "Anda telah keluar.";
        ContentCards.Children.Clear();
    }
}
