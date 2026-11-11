package ru.xelopat.app;

import android.app.*;
import android.content.*;

public class TimerReceiver extends BroadcastReceiver {
    static final String CHANNEL = "rest_silent_v2";
    static void channel(Context context) {
        NotificationManager manager = context.getSystemService(NotificationManager.class);
        manager.deleteNotificationChannel("rest_alarm_v1");
        NotificationChannel channel = new NotificationChannel(CHANNEL, "Таймер тренировки", NotificationManager.IMPORTANCE_LOW);
        channel.setDescription("Тихое уведомление. Короткий сигнал воспроизводится отдельно в наушниках.");
        channel.setSound(null, null);
        channel.enableVibration(false);
        manager.createNotificationChannel(channel);
    }
    static Notification notification(Context context, String title, String label) {
        channel(context);
        PendingIntent open = PendingIntent.getActivity(context, 0, new Intent(context, MainActivity.class),
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        return new Notification.Builder(context, CHANNEL)
            .setSmallIcon(android.R.drawable.ic_lock_idle_alarm)
            .setContentTitle(title).setContentText(label)
            .setContentIntent(open).setAutoCancel(true).build();
    }
    @Override public void onReceive(Context context, Intent intent) {
        long end = intent.getLongExtra("end", 0);
        if (end == 0 || context.getSharedPreferences("timer", 0).getLong("end", 0) != end) return;
        Intent service = new Intent(context, TimerService.class).setAction("finish")
            .putExtra("end", end).putExtra("label", intent.getStringExtra("label"));
        try {
            context.startForegroundService(service);
        } catch (RuntimeException e) {
            // Keep vibration when Android restricts a background service launch.
            context.getSharedPreferences("timer", 0).edit().remove("end").apply();
            TimerService.vibrate(context);
        }
    }
}
