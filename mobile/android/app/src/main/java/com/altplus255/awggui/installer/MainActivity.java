package com.altplus255.awggui.installer;

import android.os.Bundle;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        registerPlugin(SshSessionPlugin.class);
        super.onCreate(savedInstanceState);
    }
}
