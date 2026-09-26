<?php

declare(strict_types=1);

namespace BAJAMA\Core;

final class I18n
{
    private const SUPPORTED = ['id', 'en', 'ms', 'zh', 'ja', 'ko', 'ar', 'es', 'fr', 'de', 'pt', 'hi', 'tr', 'it', 'nl', 'ru'];

    private const LANGUAGE_NAMES = [
        'id' => 'Bahasa Indonesia', 'en' => 'English', 'ms' => 'Bahasa Melayu',
        'zh' => '简体中文', 'ja' => '日本語', 'ko' => '한국어', 'ar' => 'العربية',
        'es' => 'Español', 'fr' => 'Français', 'de' => 'Deutsch', 'pt' => 'Português',
        'hi' => 'हिन्दी', 'tr' => 'Türkçe', 'it' => 'Italiano', 'nl' => 'Nederlands', 'ru' => 'Русский',
    ];

    private const TRANSLATIONS = [
        'id' => [
            'language' => 'Bahasa', 'indonesian' => 'Indonesia', 'english' => 'English',
            'login_identifier' => 'Username atau email', 'login_identifier_help' => 'Gunakan username atau email yang terdaftar.',
            'username' => 'Username', 'email' => 'Email', 'password' => 'Password', 'password_confirmation' => 'Konfirmasi password',
            'forgot_password' => 'Lupa sandi', 'login' => 'Login', 'register' => 'Daftar',
            'identifier_required' => 'Username atau email wajib diisi.', 'invalid_credentials' => 'Username/email atau password salah.',
            'email_or_username_required' => 'Username atau email wajib diisi.',
            'recovery_help' => 'Masukkan username atau email yang terdaftar untuk menerima instruksi reset password.',
            'send_instructions' => 'Kirim Instruksi', 'back_to_login' => 'Kembali ke login',
            'username_required' => 'Username wajib diisi.', 'username_invalid' => 'Username hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.',
            'username_taken' => 'Username sudah digunakan. Silakan pilih username lain.',
            'email_taken' => 'Email sudah terdaftar. Silakan login atau gunakan menu lupa sandi.',
        ],
        'en' => [
            'language' => 'Language', 'indonesian' => 'Indonesian', 'english' => 'English',
            'login_identifier' => 'Username or email', 'login_identifier_help' => 'Use the username or email registered with BAJAMA.',
            'username' => 'Username', 'email' => 'Email', 'password' => 'Password', 'password_confirmation' => 'Confirm password',
            'forgot_password' => 'Forgot password', 'login' => 'Log in', 'register' => 'Register',
            'identifier_required' => 'Username or email is required.', 'invalid_credentials' => 'The username/email or password is incorrect.',
            'email_or_username_required' => 'Username or email is required.',
            'recovery_help' => 'Enter your registered username or email to receive password reset instructions.',
            'send_instructions' => 'Send instructions', 'back_to_login' => 'Back to login',
            'username_required' => 'Username is required.', 'username_invalid' => 'Username may contain only letters, numbers, dots, underscores, and hyphens.',
            'username_taken' => 'Username is already in use. Please choose another username.',
            'email_taken' => 'Email is already registered. Please log in or use password recovery.',
        ],
        'ms' => [
            'language' => 'Bahasa', 'indonesian' => 'Indonesia', 'english' => 'Inggeris',
            'login_identifier' => 'Nama pengguna atau e-mel', 'login_identifier_help' => 'Gunakan nama pengguna atau e-mel yang didaftarkan di BAJAMA.',
            'username' => 'Nama pengguna', 'email' => 'E-mel', 'password' => 'Kata laluan', 'password_confirmation' => 'Sahkan kata laluan',
            'forgot_password' => 'Lupa kata laluan', 'login' => 'Log masuk', 'register' => 'Daftar',
            'identifier_required' => 'Nama pengguna atau e-mel diperlukan.', 'invalid_credentials' => 'Nama pengguna/e-mel atau kata laluan tidak betul.',
            'email_or_username_required' => 'Nama pengguna atau e-mel diperlukan.', 'recovery_help' => 'Masukkan nama pengguna atau e-mel berdaftar untuk menerima arahan tetapan semula kata laluan.',
            'send_instructions' => 'Hantar arahan', 'back_to_login' => 'Kembali ke log masuk',
            'username_required' => 'Nama pengguna diperlukan.', 'username_invalid' => 'Nama pengguna hanya boleh mengandungi huruf, nombor, titik, garis bawah dan tanda sempang.',
            'username_taken' => 'Nama pengguna telah digunakan. Sila pilih nama lain.', 'email_taken' => 'E-mel telah didaftarkan. Sila log masuk atau gunakan pemulihan kata laluan.',
        ],
        'zh' => ['language' => '语言', 'login_identifier' => '用户名或电子邮件', 'username' => '用户名', 'email' => '电子邮件', 'password' => '密码', 'forgot_password' => '忘记密码', 'login' => '登录', 'register' => '注册'],
        'ja' => ['language' => '言語', 'login_identifier' => 'ユーザー名またはメール', 'username' => 'ユーザー名', 'email' => 'メール', 'password' => 'パスワード', 'forgot_password' => 'パスワードを忘れた場合', 'login' => 'ログイン', 'register' => '登録'],
        'ko' => ['language' => '언어', 'login_identifier' => '사용자 이름 또는 이메일', 'username' => '사용자 이름', 'email' => '이메일', 'password' => '비밀번호', 'forgot_password' => '비밀번호 찾기', 'login' => '로그인', 'register' => '가입'],
        'es' => ['language' => 'Idioma', 'login_identifier' => 'Usuario o correo electrónico', 'username' => 'Usuario', 'email' => 'Correo electrónico', 'password' => 'Contraseña', 'forgot_password' => 'Olvidé mi contraseña', 'login' => 'Iniciar sesión', 'register' => 'Registrarse'],
        'fr' => ['language' => 'Langue', 'login_identifier' => 'Nom d’utilisateur ou e-mail', 'username' => 'Nom d’utilisateur', 'email' => 'E-mail', 'password' => 'Mot de passe', 'forgot_password' => 'Mot de passe oublié', 'login' => 'Connexion', 'register' => 'S’inscrire'],
        'de' => ['language' => 'Sprache', 'login_identifier' => 'Benutzername oder E-Mail', 'username' => 'Benutzername', 'email' => 'E-Mail', 'password' => 'Passwort', 'forgot_password' => 'Passwort vergessen', 'login' => 'Anmelden', 'register' => 'Registrieren'],
        'pt' => ['language' => 'Idioma', 'login_identifier' => 'Nome de usuário ou e-mail', 'username' => 'Nome de usuário', 'email' => 'E-mail', 'password' => 'Senha', 'forgot_password' => 'Esqueci a senha', 'login' => 'Entrar', 'register' => 'Registar'],
        'ar' => ['language' => 'اللغة', 'login_identifier' => 'اسم المستخدم أو البريد الإلكتروني', 'username' => 'اسم المستخدم', 'email' => 'البريد الإلكتروني', 'password' => 'كلمة المرور', 'forgot_password' => 'نسيت كلمة المرور', 'login' => 'تسجيل الدخول', 'register' => 'تسجيل'],
    ];

    public static function locale(): string
    {
        $requested = strtolower(trim((string)($_GET['lang'] ?? $_POST['lang'] ?? $_SESSION['locale'] ?? 'id')));
        $locale = in_array($requested, self::SUPPORTED, true) ? $requested : 'id';
        $_SESSION['locale'] = $locale;
        return $locale;
    }

    public static function set(string $locale): string
    {
        $locale = strtolower(trim($locale));
        if (!in_array($locale, self::SUPPORTED, true)) {
            $locale = 'id';
        }
        $_SESSION['locale'] = $locale;
        return $locale;
    }

    public static function supported(): array
    {
        return self::SUPPORTED;
    }

    public static function languageName(string $locale): string
    {
        return self::LANGUAGE_NAMES[$locale] ?? strtoupper($locale);
    }

    public static function languageNames(): array
    {
        return self::LANGUAGE_NAMES;
    }

    public static function t(string $key, ?string $locale = null): string
    {
        $locale = $locale ?? self::locale();
        return self::TRANSLATIONS[$locale][$key]
            ?? self::TRANSLATIONS['en'][$key]
            ?? self::TRANSLATIONS['id'][$key]
            ?? $key;
    }
}

function t(string $key): string
{
    return I18n::t($key);
}

function locale(): string
{
    return I18n::locale();
}
