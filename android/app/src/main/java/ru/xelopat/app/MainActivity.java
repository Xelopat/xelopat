package ru.xelopat.app;

import android.app.*;
import android.content.*;
import android.media.AudioManager;
import android.net.Uri;
import android.os.*;
import android.provider.Settings;
import android.webkit.*;
import android.graphics.Color;
import android.view.*;
import android.widget.*;
import androidx.webkit.*;
import java.util.Collections;
import org.json.JSONObject;

public class MainActivity extends Activity {
    private WebView web;
    private LinearLayout controls;
    private int headerTaps;
    private long lastHeaderTap;
    private int bottomInset;
    private static boolean trusted(Uri uri) {
        return "https".equals(uri.getScheme()) && "xelopat.ru".equals(uri.getHost()) && (uri.getPort() == -1 || uri.getPort() == 443);
    }
    @Override public void onCreate(Bundle state) {
        super.onCreate(state);
        TimerReceiver.channel(this);
        getSystemService(NotificationManager.class).cancel(1);
        setVolumeControlStream(AudioManager.STREAM_MUSIC);
        getWindow().setStatusBarColor(Color.BLACK);
        getWindow().setNavigationBarColor(Color.BLACK);
        if (Build.VERSION.SDK_INT >= 29) {
            getWindow().setStatusBarContrastEnforced(false);
            getWindow().setNavigationBarContrastEnforced(false);
        }
        getWindow().getDecorView().setSystemUiVisibility(View.SYSTEM_UI_FLAG_LAYOUT_STABLE
            | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION);
        if (Build.VERSION.SDK_INT >= 30) {
            getWindow().setDecorFitsSystemWindows(false);
            getWindow().getInsetsController().setSystemBarsAppearance(0,
                WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS | WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS);
        }
        LinearLayout layout = new LinearLayout(this);
        layout.setOrientation(LinearLayout.VERTICAL);
        layout.setBackgroundColor(Color.BLACK);
        controls = new LinearLayout(this);
        controls.setBackgroundColor(Color.rgb(21, 21, 24));
        Button settings = new Button(this);
        settings.setText("Звук и разрешения таймера");
        settings.setOnClickListener(v -> showSettings());
        controls.addView(settings, new LinearLayout.LayoutParams(0, -2, 1));
        Button hide = new Button(this);
        hide.setText("Скрыть");
        hide.setOnClickListener(v -> {
            setControlsVisible(false);
            Toast.makeText(this, "Вернуть панель — 7 быстрых нажатий по шапке сайта", Toast.LENGTH_LONG).show();
        });
        controls.addView(hide);
        layout.addView(controls);
        setControlsVisible(!getPreferences(0).getBoolean("controlsHidden", false));
        web = new WebView(this);
        web.setBackgroundColor(Color.rgb(21, 21, 24));
        layout.addView(web, new LinearLayout.LayoutParams(-1, 0, 1));
        setContentView(layout);
        layout.setOnApplyWindowInsetsListener((view, insets) -> {
            int bottom = insets.getSystemWindowInsetBottom();
            int keyboard = 0;
            if (Build.VERSION.SDK_INT >= 30) {
                keyboard = insets.getInsets(WindowInsets.Type.ime()).bottom;
                bottom = insets.getInsets(WindowInsets.Type.navigationBars()).bottom;
            } else if (bottom > 100 * getResources().getDisplayMetrics().density) {
                keyboard = bottom;
            }
            view.setPadding(insets.getSystemWindowInsetLeft(), insets.getSystemWindowInsetTop(),
                insets.getSystemWindowInsetRight(), keyboard);
            bottomInset = keyboard > 0 ? 0 : bottom;
            updateWebInsets();
            return insets;
        });
        layout.requestApplyInsets();
        web.getSettings().setJavaScriptEnabled(true);
        web.getSettings().setDomStorageEnabled(true);
        web.getSettings().setAllowFileAccess(false);
        web.getSettings().setAllowContentAccess(false);
        web.getSettings().setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        web.setWebViewClient(new WebViewClient() {
            @Override public void onPageFinished(WebView view, String url) {
                if (!trusted(Uri.parse(url))) return;
                updateWebInsets();
                view.evaluateJavascript(
                    "(function(){if(window.__xelopatHeaderTap)return;window.__xelopatHeaderTap=true;"
                    + "document.addEventListener('click',function(e){if(!window.XelopatNative)return;"
                    + "var header=e.target.closest&&e.target.closest('#siteHeader');"
                    + "window.XelopatNative.postMessage(JSON.stringify({action:header?'headerTap':'resetHeaderTaps'}));"
                    + "},true);})();", null);
            }
            @Override public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                if (trusted(request.getUrl())) return false;
                if (request.isForMainFrame() && ("https".equals(request.getUrl().getScheme()) || "http".equals(request.getUrl().getScheme()))) {
                    startActivity(new Intent(Intent.ACTION_VIEW, request.getUrl()));
                }
                return true;
            }
        });
        if (WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) {
            WebViewCompat.addWebMessageListener(web, "XelopatNative", Collections.singleton("https://xelopat.ru"),
                (view, message, origin, mainFrame, reply) -> {
                    if (!mainFrame || !trusted(origin)) return;
                    try {
                        JSONObject data = new JSONObject(message.getData());
                        String action = data.getString("action");
                        if ("headerTap".equals(action)) {
                            long now = SystemClock.elapsedRealtime();
                            headerTaps = now - lastHeaderTap <= 900 ? headerTaps + 1 : 1;
                            lastHeaderTap = now;
                            if (headerTaps == 7) {
                                setControlsVisible(true);
                                headerTaps = 0;
                            }
                            return;
                        }
                        if ("resetHeaderTaps".equals(action)) { headerTaps = 0; return; }
                        if ("cancel".equals(action)) { cancelTimer(); return; }
                        if (!"start".equals(action)) return;
                        AlarmManager alarms = getSystemService(AlarmManager.class);
                        if (Build.VERSION.SDK_INT >= 31 && !alarms.canScheduleExactAlarms()) {
                            reply.postMessage("Разреши будильники и напоминания через кнопку настроек приложения.");
                            return;
                        }
                        long end = data.getLong("end");
                        long delay = end - System.currentTimeMillis();
                        if (delay <= 0 || delay > 24 * 60 * 60 * 1000L) return;
                        cancelTimer();
                        String label = data.optString("label", "Отдых завершён");
                        Intent alarm = new Intent(this, TimerReceiver.class).putExtra("end", end).putExtra("label", label);
                        PendingIntent pending = PendingIntent.getBroadcast(this, 1, alarm, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
                        getSharedPreferences("timer", 0).edit().putLong("end", end).apply();
                        alarms.setExactAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, SystemClock.elapsedRealtime() + delay, pending);
                        startForegroundService(new Intent(this, TimerService.class).setAction("wait")
                            .putExtra("end", end).putExtra("label", label));
                    } catch (Exception e) {
                        reply.postMessage("Не удалось включить фоновый сигнал. Проверь разрешения приложения.");
                    }
                });
        }
        if (!WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) {
            new AlertDialog.Builder(this).setMessage("Обнови Android System WebView, чтобы включить фоновый таймер.")
                .setPositiveButton("ОК", null).show();
        }
        web.loadUrl("https://xelopat.ru/workout");
    }
    private void cancelTimer() {
        stopService(new Intent(this, TimerService.class));
        PendingIntent pending = PendingIntent.getBroadcast(this, 1, new Intent(this, TimerReceiver.class),
            PendingIntent.FLAG_NO_CREATE | PendingIntent.FLAG_IMMUTABLE);
        if (pending != null) getSystemService(AlarmManager.class).cancel(pending);
        getSharedPreferences("timer", 0).edit().remove("end").apply();
        getSystemService(NotificationManager.class).cancel(1);
    }
    private void setControlsVisible(boolean visible) {
        controls.setVisibility(visible ? View.VISIBLE : View.GONE);
        getPreferences(0).edit().putBoolean("controlsHidden", !visible).apply();
    }
    private void updateWebInsets() {
        if (web == null || web.getUrl() == null || !trusted(Uri.parse(web.getUrl()))) return;
        float pixels = bottomInset / getResources().getDisplayMetrics().density;
        web.evaluateJavascript(
            "(function(){var s=document.getElementById('xelopatNativeInsets');"
            + "if(!s){s=document.createElement('style');s.id='xelopatNativeInsets';document.head.appendChild(s);}"
            + "s.textContent='body{padding-bottom:" + pixels + "px!important}"
            + ".wo-timer{bottom:max(14px," + pixels + "px)!important}';})();", null);
    }
    private void showSettings() {
        String[] choices = {"Разрешить точные таймеры", "Громкость в наушниках"};
        new AlertDialog.Builder(this).setTitle("Таймер тренировки").setItems(choices, (dialog, which) -> {
            if (which == 0) {
                if (Build.VERSION.SDK_INT >= 31) {
                    startActivity(new Intent(Settings.ACTION_REQUEST_SCHEDULE_EXACT_ALARM, Uri.parse("package:" + getPackageName())));
                } else {
                    Toast.makeText(this, "Точные таймеры доступны", Toast.LENGTH_SHORT).show();
                }
            } else {
                getSystemService(AudioManager.class).adjustStreamVolume(AudioManager.STREAM_MUSIC, AudioManager.ADJUST_SAME, AudioManager.FLAG_SHOW_UI);
            }
        }).show();
    }
    @Override public void onBackPressed() {
        if (web.canGoBack()) web.goBack(); else super.onBackPressed();
    }
    @Override protected void onDestroy() {
        web.destroy();
        super.onDestroy();
    }
}
