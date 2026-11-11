package ru.xelopat.app;

import android.Manifest;
import android.app.*;
import android.content.*;
import android.media.AudioManager;
import android.net.Uri;
import android.os.*;
import android.provider.Settings;
import android.webkit.*;
import android.widget.*;
import androidx.webkit.*;
import java.util.Collections;
import org.json.JSONObject;

public class MainActivity extends Activity {
    private WebView web;
    private static boolean trusted(Uri uri) {
        return "https".equals(uri.getScheme()) && "xelopat.ru".equals(uri.getHost()) && (uri.getPort() == -1 || uri.getPort() == 443);
    }
    @Override public void onCreate(Bundle state) {
        super.onCreate(state);
        TimerReceiver.channel(this);
        setVolumeControlStream(AudioManager.STREAM_MUSIC);
        LinearLayout layout = new LinearLayout(this);
        layout.setOrientation(LinearLayout.VERTICAL);
        Button settings = new Button(this);
        settings.setText("Звук и разрешения таймера");
        settings.setOnClickListener(v -> showSettings());
        layout.addView(settings);
        web = new WebView(this);
        layout.addView(web, new LinearLayout.LayoutParams(-1, 0, 1));
        setContentView(layout);
        layout.setOnApplyWindowInsetsListener((view, insets) -> {
            view.setPadding(insets.getSystemWindowInsetLeft(), insets.getSystemWindowInsetTop(),
                insets.getSystemWindowInsetRight(), insets.getSystemWindowInsetBottom());
            return insets;
        });
        web.getSettings().setJavaScriptEnabled(true);
        web.getSettings().setDomStorageEnabled(true);
        web.getSettings().setAllowFileAccess(false);
        web.getSettings().setAllowContentAccess(false);
        web.getSettings().setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        web.setWebViewClient(new WebViewClient() {
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
                        if ("cancel".equals(action)) { cancelTimer(); return; }
                        if (!"start".equals(action)) return;
                        AlarmManager alarms = getSystemService(AlarmManager.class);
                        if (!getSystemService(NotificationManager.class).areNotificationsEnabled()) {
                            reply.postMessage("Разреши уведомления через кнопку настроек приложения.");
                            return;
                        }
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
    private void showSettings() {
        String[] choices = {"Разрешить уведомления", "Разрешить точные таймеры", "Уведомление таймера", "Громкость в наушниках"};
        new AlertDialog.Builder(this).setTitle("Таймер тренировки").setItems(choices, (dialog, which) -> {
            if (which == 0) {
                if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != android.content.pm.PackageManager.PERMISSION_GRANTED) {
                    requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, 1);
                } else {
                    startActivity(new Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, getPackageName()));
                }
            } else if (which == 1 && Build.VERSION.SDK_INT >= 31) {
                startActivity(new Intent(Settings.ACTION_REQUEST_SCHEDULE_EXACT_ALARM, Uri.parse("package:" + getPackageName())));
            } else if (which == 2) {
                startActivity(new Intent(Settings.ACTION_CHANNEL_NOTIFICATION_SETTINGS)
                    .putExtra(Settings.EXTRA_APP_PACKAGE, getPackageName()).putExtra(Settings.EXTRA_CHANNEL_ID, TimerReceiver.CHANNEL));
            } else if (which == 3) {
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
