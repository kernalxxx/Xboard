<?php

use App\Services\ThemeService;
use App\Services\UpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

$renderFrontendTheme = function (Request $request) {
    if (admin_setting('app_url') && admin_setting('safe_mode_enable', 0)) {
        $requestHost = $request->getHost();
        $configHost = parse_url(admin_setting('app_url'), PHP_URL_HOST);

        if ($requestHost !== $configHost) {
            abort(403);
        }
    }

    $theme = admin_setting('frontend_theme', 'Xboard');
    $themeService = new ThemeService();

    try {
        if (!$themeService->exists($theme)) {
            if ($theme !== 'Xboard') {
                Log::warning('Theme not found, switching to default theme', ['theme' => $theme]);
                $theme = 'Xboard';
                admin_setting(['frontend_theme' => $theme]);
            }
            $themeService->switch($theme);
        }

        if (!$themeService->getThemeViewPath($theme)) {
            throw new Exception('主题视图文件不存在');
        }

        $publicThemePath = public_path('theme/' . $theme);
        if (!File::exists($publicThemePath)) {
            $themePath = $themeService->getThemePath($theme);
            if (!$themePath || !File::copyDirectory($themePath, $publicThemePath)) {
                throw new Exception('主题初始化失败');
            }
            Log::info('Theme initialized in public directory', ['theme' => $theme]);
        }

        $renderParams = [
            'title' => admin_setting('app_name', 'Xboard'),
            'theme' => $theme,
            'version' => app(UpdateService::class)->getCurrentVersion(),
            'description' => admin_setting('app_description', 'Xboard is best'),
            'logo' => admin_setting('logo'),
            'theme_config' => $themeService->getConfig($theme)
        ];
        return view('theme::' . $theme . '.dashboard', $renderParams);
    } catch (Exception $e) {
        Log::error('Theme rendering failed', [
            'theme' => $theme,
            'error' => $e->getMessage()
        ]);
        abort(500, '主题加载失败');
    }
};

$normalizeRoutePrefix = fn($value, string $fallback) => trim((string) $value, '/') !== ''
    ? trim((string) $value, '/')
    : $fallback;

$defaultAdminPath = hash('crc32b', config('app.key'));
$adminPath = $normalizeRoutePrefix(
    admin_setting('secure_path', admin_setting('frontend_admin_path', $defaultAdminPath)),
    $defaultAdminPath
);
$subscribePath = $normalizeRoutePrefix(admin_setting('subscribe_path', 's'), 's');

Route::get('/', $renderFrontendTheme);

//TODO:: 兼容
Route::get('/' . $adminPath, function () use ($adminPath) {
    return view('admin', [
        'title' => admin_setting('app_name', 'XBoard'),
        'theme_sidebar' => admin_setting('frontend_theme_sidebar', 'light'),
        'theme_header' => admin_setting('frontend_theme_header', 'dark'),
        'theme_color' => admin_setting('frontend_theme_color', 'default'),
        'background_url' => admin_setting('frontend_background_url'),
        'version' => app(UpdateService::class)->getCurrentVersion(),
        'logo' => admin_setting('logo'),
        'secure_path' => $adminPath
    ]);
});

Route::get('/' . $subscribePath . '/{token}', [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe'])
    ->middleware('client')
    ->name('client.subscribe');

$frontendReservedPrefixes = array_values(array_filter([
    'api',
    'theme',
    'assets',
    'storage',
    $adminPath,
    $subscribePath,
], fn($prefix) => trim((string) $prefix, '/') !== ''));

$frontendReservedPattern = implode('|', array_map(
    fn($prefix) => preg_quote(trim((string) $prefix, '/'), '#'),
    $frontendReservedPrefixes
));

Route::get('/{path}', $renderFrontendTheme)
    ->where('path', '(?!(?:' . $frontendReservedPattern . ')(?:/|$))(?!.*\.[^/]+$).+')
    ->name('frontend.spa');
