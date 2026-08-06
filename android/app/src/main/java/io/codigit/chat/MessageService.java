package io.codigit.chat;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.net.ConnectivityManager;
import android.net.NetworkInfo;
import android.os.Build;
import android.os.IBinder;
import android.webkit.CookieManager;

import androidx.core.app.NotificationCompat;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;

/**
 * Drži otvoren zahtjev prema serveru koji se vrati čim stigne nova poruka,
 * pa je prikaže kao obavijest. Ne koristi Google servise — radi na svakom
 * Androidu, uključujući uređaje bez Googleovih usluga.
 */
public class MessageService extends Service {

    private static final String CH_MESSAGES = "messages";
    private static final String CH_SERVICE  = "service";
    private static final int    ID_SERVICE  = 1;

    private volatile boolean running = false;
    private Thread worker;
    private long lastId = 0;

    @Override
    public void onCreate() {
        super.onCreate();
        createChannels();
        lastId = getSharedPreferences("chat", MODE_PRIVATE).getLong("lastId", 0);
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        startForeground(ID_SERVICE, buildServiceNotification());
        if (!running) {
            running = true;
            worker = new Thread(this::loop, "chat-wait");
            worker.start();
        }
        return START_STICKY;   // sustav neka je ponovno pokrene ako je ugasi
    }

    /** Petlja: čeka odgovor servera, javlja poruke, pa opet — s pauzom kad nema mreže. */
    private void loop() {
        String base = getString(R.string.chat_url);
        while (running) {
            if (!isOnline()) { sleep(15000); continue; }
            try {
                String url = base + "api.php?action=wait&since=" + lastId;
                HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
                c.setRequestProperty("Cookie", cookiesFor(base));
                c.setConnectTimeout(15000);
                c.setReadTimeout(40000);   // server drži vezu do ~25 s

                int code = c.getResponseCode();
                if (code == 401 || code == 403) { sleep(60000); c.disconnect(); continue; }
                if (code != 200) { sleep(10000); c.disconnect(); continue; }

                StringBuilder sb = new StringBuilder();
                try (BufferedReader r = new BufferedReader(new InputStreamReader(c.getInputStream()))) {
                    for (String line; (line = r.readLine()) != null; ) sb.append(line);
                }
                c.disconnect();

                JSONObject o = new JSONObject(sb.toString());
                JSONArray msgs = o.optJSONArray("messages");
                if (msgs != null) {
                    for (int i = 0; i < msgs.length(); i++) {
                        JSONObject m = msgs.getJSONObject(i);
                        notifyMessage(m.optInt("conv"), m.optString("title", "Our Chat"),
                                m.optString("body", "New message"));
                    }
                }
                long newLast = o.optLong("last_id", lastId);
                if (newLast > lastId) {
                    lastId = newLast;
                    getSharedPreferences("chat", MODE_PRIVATE).edit().putLong("lastId", lastId).apply();
                }
            } catch (Exception e) {
                sleep(10000);   // mreža/server — pokušaj ponovno
            }
        }
    }

    /** Kolačići prijave dijele se s WebViewom, pa služba ne treba vlastitu prijavu. */
    private String cookiesFor(String url) {
        String c = CookieManager.getInstance().getCookie(url);
        return c == null ? "" : c;
    }

    private boolean isOnline() {
        ConnectivityManager cm = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
        if (cm == null) return false;
        NetworkInfo n = cm.getActiveNetworkInfo();
        return n != null && n.isConnected();
    }

    private void sleep(long ms) {
        try { Thread.sleep(ms); } catch (InterruptedException ignored) { }
    }

    private void createChannels() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return;
        NotificationManager nm = getSystemService(NotificationManager.class);

        NotificationChannel msgs = new NotificationChannel(CH_MESSAGES,
                getString(R.string.notif_channel_messages), NotificationManager.IMPORTANCE_HIGH);
        msgs.enableVibration(true);
        nm.createNotificationChannel(msgs);

        NotificationChannel svc = new NotificationChannel(CH_SERVICE,
                getString(R.string.notif_channel_service), NotificationManager.IMPORTANCE_MIN);
        svc.setShowBadge(false);
        nm.createNotificationChannel(svc);
    }

    private Notification buildServiceNotification() {
        return new NotificationCompat.Builder(this, CH_SERVICE)
                .setContentTitle(getString(R.string.service_running))
                .setSmallIcon(R.drawable.ic_notification)
                .setPriority(NotificationCompat.PRIORITY_MIN)
                .setOngoing(true)
                .setContentIntent(openAppIntent(0))
                .build();
    }

    private void notifyMessage(int conv, String title, String body) {
        Notification n = new NotificationCompat.Builder(this, CH_MESSAGES)
                .setContentTitle(title)
                .setContentText(body)
                .setStyle(new NotificationCompat.BigTextStyle().bigText(body))
                .setSmallIcon(R.drawable.ic_notification)
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setDefaults(NotificationCompat.DEFAULT_ALL)
                .setAutoCancel(true)
                .setContentIntent(openAppIntent(conv))
                .build();
        NotificationManager nm = getSystemService(NotificationManager.class);
        if (nm != null) nm.notify(1000 + conv, n);
    }

    private PendingIntent openAppIntent(int conv) {
        Intent i = new Intent(this, MainActivity.class)
                .addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP)
                .putExtra("fromNotification", true)
                .putExtra("conv", conv);
        int flags = PendingIntent.FLAG_UPDATE_CURRENT
                | (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M ? PendingIntent.FLAG_IMMUTABLE : 0);
        return PendingIntent.getActivity(this, conv, i, flags);
    }

    @Override
    public void onDestroy() {
        running = false;
        if (worker != null) worker.interrupt();
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) { return null; }
}
