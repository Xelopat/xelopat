package ru.xelopat.app;

import android.app.*;
import android.content.*;
import android.media.*;
import android.os.*;
import java.io.IOException;

public class TimerService extends Service {
    private final Handler handler = new Handler(Looper.getMainLooper());
    private MediaPlayer player;
    private AudioManager audio;
    private AudioFocusRequest focus;
    private boolean audible;
    private int outputId;
    private final BroadcastReceiver noisy = new BroadcastReceiver() {
        @Override public void onReceive(Context context, Intent intent) { stopSignal(); }
    };
    private final AudioDeviceCallback devices = new AudioDeviceCallback() {
        @Override public void onAudioDevicesRemoved(AudioDeviceInfo[] removed) {
            for (AudioDeviceInfo device : removed) {
                if (device.getId() == outputId) stopSignal();
            }
        }
    };
    static void vibrate(Context context) {
        Vibrator vibrator = context.getSystemService(Vibrator.class);
        if (vibrator != null) vibrator.vibrate(VibrationEffect.createWaveform(new long[]{0, 300, 150, 300}, -1));
    }
    private static boolean headphones(AudioDeviceInfo device) {
        if (device == null) return false;
        int type = device.getType();
        return type == AudioDeviceInfo.TYPE_WIRED_HEADPHONES || type == AudioDeviceInfo.TYPE_WIRED_HEADSET
            || type == AudioDeviceInfo.TYPE_BLUETOOTH_A2DP || type == AudioDeviceInfo.TYPE_USB_HEADSET
            || type == AudioDeviceInfo.TYPE_BLE_HEADSET || type == AudioDeviceInfo.TYPE_HEARING_AID;
    }
    @Override public void onCreate() {
        super.onCreate();
        audio = getSystemService(AudioManager.class);
        audio.registerAudioDeviceCallback(devices, handler);
        IntentFilter filter = new IntentFilter(AudioManager.ACTION_AUDIO_BECOMING_NOISY);
        if (Build.VERSION.SDK_INT >= 33) registerReceiver(noisy, filter, Context.RECEIVER_NOT_EXPORTED);
        else registerReceiver(noisy, filter);
    }
    @Override public int onStartCommand(Intent intent, int flags, int startId) {
        String label = intent == null ? "Отдых" : intent.getStringExtra("label");
        startForeground(2, TimerReceiver.notification(this, "Таймер работает", label));
        releaseAudio();
        handler.removeCallbacksAndMessages(null);
        long end = intent == null ? 0 : intent.getLongExtra("end", 0);
        if (end == 0 || getSharedPreferences("timer", 0).getLong("end", 0) != end) {
            stopSignal();
            return START_NOT_STICKY;
        }
        if (!"finish".equals(intent.getAction())) {
            // Exact AlarmManager alarm remains authoritative if this service is killed.
            handler.postDelayed(this::stopSignal, Math.max(0, end - System.currentTimeMillis()) + 60000);
            return START_NOT_STICKY;
        }
        getSharedPreferences("timer", 0).edit().remove("end").apply();
        vibrate(this);
        AudioDeviceInfo output = null;
        for (AudioDeviceInfo device : audio.getDevices(AudioManager.GET_DEVICES_OUTPUTS)) {
            if (headphones(device)) { output = device; break; }
        }
        if (output == null || Build.VERSION.SDK_INT < 28) {
            stopSignal();
            return START_NOT_STICKY;
        }
        outputId = output.getId();
        AudioAttributes attributes = new AudioAttributes.Builder()
            .setUsage(AudioAttributes.USAGE_MEDIA).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build();
        focus = new AudioFocusRequest.Builder(AudioManager.AUDIOFOCUS_GAIN_TRANSIENT_MAY_DUCK)
            .setAudioAttributes(attributes)
            .setOnAudioFocusChangeListener(change -> { if (change < 0) stopSignal(); }, handler).build();
        if (audio.requestAudioFocus(focus) != AudioManager.AUDIOFOCUS_REQUEST_GRANTED) {
            stopSignal();
            return START_NOT_STICKY;
        }
        try {
            player = new MediaPlayer();
            player.setAudioAttributes(attributes);
            player.setVolume(0, 0);
            try (android.content.res.AssetFileDescriptor file = getResources().openRawResourceFd(R.raw.timer)) {
                player.setDataSource(file.getFileDescriptor(), file.getStartOffset(), file.getLength());
            }
            if (!player.setPreferredDevice(output)) { stopSignal(); return START_NOT_STICKY; }
            player.addOnRoutingChangedListener(router -> checkRoute(), handler);
            player.setOnCompletionListener(p -> stopSelf());
            player.setOnErrorListener((p, what, extra) -> { stopSignal(); return true; });
            player.prepare();
            player.start();
            handler.postDelayed(this::checkRoute, 100);
            handler.postDelayed(this::stopSignal, 2000);
        } catch (IOException | RuntimeException e) {
            stopSignal();
        }
        return START_NOT_STICKY;
    }
    private void checkRoute() {
        if (player == null) return;
        AudioDeviceInfo routed = player.getRoutedDevice();
        if (routed == null && !audible) {
            handler.postDelayed(this::checkRoute, 50);
            return;
        }
        if (!headphones(routed) || routed.getId() != outputId) {
            stopSignal();
            return;
        }
        if (!audible) {
            player.setVolume(1, 1);
            audible = true;
            handler.postDelayed(this::stopSignal, 650);
        }
    }
    private void stopSignal() {
        releaseAudio();
        stopSelf();
    }
    private void releaseAudio() {
        audible = false;
        outputId = 0;
        if (player != null) { player.release(); player = null; }
        if (focus != null) { audio.abandonAudioFocusRequest(focus); focus = null; }
    }
    @Override public void onDestroy() {
        handler.removeCallbacksAndMessages(null);
        releaseAudio();
        audio.unregisterAudioDeviceCallback(devices);
        unregisterReceiver(noisy);
        stopForeground(STOP_FOREGROUND_REMOVE);
        super.onDestroy();
    }
    @Override public IBinder onBind(Intent intent) { return null; }
}
