package ru.xelopat.app;

import android.app.*;
import android.content.*;
import android.media.AudioAttributes;
import android.net.Uri;

public class TimerReceiver extends BroadcastReceiver {
    static final String CHANNEL = "rest_alarm_v1";
    static void channel(Context context) {
        NotificationChannel channel = new NotificationChannel(CHANNEL, "Таймер тренировки", NotificationManager.IMPORTANCE_HIGH);
        channel.setDescription("Сигнал окончания отдыха. Громкость регулируется как у будильника.");
        channel.setSound(Uri.parse("android.resource://" + context.getPackageName() + "/" + ru.xelopat.app.R.raw.timer),
            new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_ALARM).build());
        channel.enableVibration(true);
        channel.setVibrationPattern(new long[]{0, 500, 200, 500, 200, 500});
        context.getSystemService(NotificationManager.class).createNotificationChannel(channel);
    }
    @Override public void onReceive(Context context, Intent intent) {
        long end = intent.getLongExtra("end", 0);
        android.content.SharedPreferences prefs = context.getSharedPreferences("timer", 0);
        if (end == 0 || prefs.getLong("end", 0) != end) return;
        prefs.edit().remove("end").apply();
        channel(context);
        PendingIntent open = PendingIntent.getActivity(context, 0, new Intent(context, MainActivity.class),
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        Notification notification = new Notification.Builder(context, CHANNEL)
            .setSmallIcon(android.R.drawable.ic_lock_idle_alarm)
            .setContentTitle("Таймер завершён")
            .setContentText(intent.getStringExtra("label"))
            .setCategory(Notification.CATEGORY_ALARM)
            .setContentIntent(open).setAutoCancel(true).build();
        context.getSystemService(NotificationManager.class).notify(1, notification);
    }
}
