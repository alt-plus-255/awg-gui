package com.altplus255.awggui.installer;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.jcraft.jsch.ChannelExec;
import com.jcraft.jsch.JSch;
import com.jcraft.jsch.Session;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.Properties;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

@CapacitorPlugin(name = "SshSession")
public class SshSessionPlugin extends Plugin {
    private final ExecutorService executor = Executors.newSingleThreadExecutor();
    private Session session;

    @PluginMethod
    public void connect(PluginCall call) {
        String host = call.getString("host");
        Integer port = call.getInt("port", 22);
        String username = call.getString("username", "root");
        String password = call.getString("password");

        if (host == null || host.isEmpty()) {
            call.reject("host is required");
            return;
        }
        if (password == null) {
            call.reject("password is required");
            return;
        }

        executor.execute(() -> {
            try {
                disconnectQuietly();
                JSch jsch = new JSch();
                Session s = jsch.getSession(username, host, port == null ? 22 : port);
                s.setPassword(password);
                Properties config = new Properties();
                config.put("StrictHostKeyChecking", "no");
                config.put("PreferredAuthentications", "password");
                s.setConfig(config);
                s.setTimeout(30000);
                s.connect(30000);
                session = s;

                JSObject ret = new JSObject();
                ret.put("ok", true);
                call.resolve(ret);
            } catch (Exception e) {
                call.reject("SSH connect failed: " + e.getMessage(), e);
            }
        });
    }

    @PluginMethod
    public void exec(PluginCall call) {
        String command = call.getString("command");
        if (command == null || command.isEmpty()) {
            call.reject("command is required");
            return;
        }

        executor.execute(() -> {
            ChannelExec channel = null;
            try {
                Session s = session;
                if (s == null || !s.isConnected()) {
                    call.reject("Not connected");
                    return;
                }

                channel = (ChannelExec) s.openChannel("exec");
                channel.setCommand(command);
                channel.setInputStream(null);

                InputStream stdout = channel.getInputStream();
                InputStream stderr = channel.getErrStream();
                channel.connect(15000);

                ByteArrayOutputStream outBuf = new ByteArrayOutputStream();
                ByteArrayOutputStream errBuf = new ByteArrayOutputStream();
                byte[] buffer = new byte[4096];

                while (true) {
                    while (stdout.available() > 0) {
                        int n = stdout.read(buffer);
                        if (n < 0) break;
                        outBuf.write(buffer, 0, n);
                        emitOutput("stdout", new String(buffer, 0, n, StandardCharsets.UTF_8));
                    }
                    while (stderr.available() > 0) {
                        int n = stderr.read(buffer);
                        if (n < 0) break;
                        errBuf.write(buffer, 0, n);
                        emitOutput("stderr", new String(buffer, 0, n, StandardCharsets.UTF_8));
                    }
                    if (channel.isClosed()) {
                        while (stdout.available() > 0) {
                            int n = stdout.read(buffer);
                            if (n < 0) break;
                            outBuf.write(buffer, 0, n);
                            emitOutput("stdout", new String(buffer, 0, n, StandardCharsets.UTF_8));
                        }
                        while (stderr.available() > 0) {
                            int n = stderr.read(buffer);
                            if (n < 0) break;
                            errBuf.write(buffer, 0, n);
                            emitOutput("stderr", new String(buffer, 0, n, StandardCharsets.UTF_8));
                        }
                        break;
                    }
                    Thread.sleep(80);
                }

                JSObject ret = new JSObject();
                ret.put("code", channel.getExitStatus());
                ret.put("stdout", outBuf.toString(StandardCharsets.UTF_8.name()));
                ret.put("stderr", errBuf.toString(StandardCharsets.UTF_8.name()));
                call.resolve(ret);
            } catch (Exception e) {
                call.reject("SSH exec failed: " + e.getMessage(), e);
            } finally {
                if (channel != null) {
                    try {
                        channel.disconnect();
                    } catch (Exception ignored) {
                    }
                }
            }
        });
    }

    @PluginMethod
    public void disconnect(PluginCall call) {
        executor.execute(() -> {
            disconnectQuietly();
            JSObject ret = new JSObject();
            ret.put("ok", true);
            call.resolve(ret);
        });
    }

    private void emitOutput(String stream, String data) {
        JSObject event = new JSObject();
        event.put("stream", stream);
        event.put("data", data);
        notifyListeners("output", event);
    }

    private void disconnectQuietly() {
        if (session != null) {
            try {
                session.disconnect();
            } catch (Exception ignored) {
            }
            session = null;
        }
    }

    @Override
    public void handleOnDestroy() {
        disconnectQuietly();
        executor.shutdownNow();
        super.handleOnDestroy();
    }
}
