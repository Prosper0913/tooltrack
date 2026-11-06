# FPST ToolTrack PWA — local XAMPP installation

> Paths and shell commands in this document are relative to the ToolTrack V2 project root, unless explicitly stated otherwise.

## Current server

- App: **https://192.168.1.4:8443/tooltrack/**
- Installation / browser status: **https://192.168.1.4:8443/tooltrack/pwa/install.html**
- Public Android/Windows CA certificate: `C:\xampp\htdocs\tooltrack\pwa\tooltrack-local-ca.cer`
- Public certificate download (bootstrap only): `http://192.168.1.4/tooltrack/pwa/tooltrack-local-ca.cer`
- CA certificate SHA-256 fingerprint: `23:0D:09:FB:C2:19:A9:8A:21:98:F7:B9:CA:F6:BD:C7:5F:32:BF:F1:71:BB:A3:C5:DB:22:C9:76:DD:48:71:F0`
- Apache HTTPS port: **8443**. Existing HTTP/443 virtual hosts are unchanged.
- The local CA is already trusted for the Windows user who ran setup.

Reserve **192.168.1.4** for this computer in the Wi-Fi router's DHCP settings. Android and desktop must use the same stable HTTPS address, on the same local network. `localhost` on the phone refers to the phone, not the XAMPP computer.

## Install on Redmi Note 13

1. Keep Apache and MySQL running in XAMPP. Connect the Redmi to the same Wi-Fi; guest/client-isolated Wi-Fi will not reach the server.
2. Copy **only** `tooltrack-local-ca.cer` to the phone using USB/file transfer, or download that public certificate from the bootstrap-only HTTP link above. Do not enter credentials on the HTTP address.
3. In MIUI/HyperOS Settings, search **Install a certificate** or **Encryption & credentials**. Choose **CA certificate**, select the file and confirm the Android certificate installation prompt. Menu names vary by system version. If prompted, unlock the phone. This is a CA certificate, not a client/VPN or Wi-Fi authentication certificate.
4. In the certificate details, compare its SHA-256 fingerprint with the value printed above/on the server. The certificate's name is **FPST ToolTrack Local CA**.
5. Fully close and reopen Chrome. Open **https://192.168.1.4:8443/tooltrack/pwa/install.html**. It must open normally with no certificate warning; proceeding through a warning does not establish a trusted PWA connection.
6. Tap Chrome's menu → **Add to Home screen → Install**, or **Install app**. You can also use the install button on the setup page when Chrome makes it available.
7. Open **ToolTrack** from the home screen/app drawer. It opens in its own standalone window and uses the existing login and modules.

The certificate trust step must be done on the phone itself; server-side setup cannot install a certificate or accept the app installation prompt on the Redmi.

## Install on iPhone / iPad

1. Connect to the same Wi-Fi and open Safari.
2. Download/transfer only the public `pwa/tooltrack-local-ca.cer` certificate.
3. Install the certificate profile under **Settings → General → VPN & Device Management** (or **Profile Downloaded**).
4. Open **Settings → General → About → Certificate Trust Settings** and enable **full trust** for **FPST ToolTrack Local CA**. Installing a downloaded certificate profile alone does not enable SSL trust.
5. Reopen Safari and visit **https://192.168.1.4:8443/tooltrack/** without certificate warnings.
6. Tap **Share → Add to Home Screen**, enable **Open as Web App** if available, and tap **Add**. There is no requirement for Safari to display the Android-style Install button.
7. Launch from the Home Screen, log in, and allow camera access when using the existing scanner. Verify scanning and a designated test return on the actual iPhone before using it operationally.

The iPhone needs the same certificate, correct automatic date/time, trusted HTTPS, running server and local network connection. A managed iPhone may require its administrator to approve certificate profiles.

Testing scope: the Windows workspace has no connected iPhone or Safari/WebKit engine. `tests/pwa_check.ps1 -IphoneLayout` checks iPhone-sized pages in **Chrome emulation**, not real iOS Safari. Physical certificate trust, Home Screen installation and camera behavior must be checked on the iPhone itself.

References: [Apple certificate trust](https://support.apple.com/en-sg/102390), [Safari Home Screen installation](https://support.apple.com/en-lamr/guide/iphone/iphea86e5236/ios).

## Install on Windows

1. On this server computer, the setup already imported the CA into **Current User → Trusted Root Certification Authorities**. Restart Chrome/Edge if it was open during setup.
2. On any additional Windows computer, open `tooltrack-local-ca.cer` → **Install Certificate → Current User → Place all certificates in the following store → Trusted Root Certification Authorities**. Verify the same fingerprint.
3. Open **https://192.168.1.4:8443/tooltrack/pwa/install.html** in Edge or Chrome without certificate warnings.
4. Click the address-bar install icon, or use Edge's **Apps / Install this site as an app** / Chrome's **Install app** menu command. Browser wording can vary. Confirm installation and pin it to Start/taskbar if desired.
5. Launch ToolTrack from Start/taskbar. Standard login/session and permissions still apply.

## What is cached, and what is not

The service worker is scoped to `/tooltrack/`. Its Cache Storage allowlist contains exactly:

- `offline.html` — a generic connection message, containing no student/user data.
- `assets/pwa/icon-192.png`
- `assets/pwa/icon-512.png`

It does **not** cache application PHP/HTML pages, API responses, students, reports, CSV uploads/exports, session tokens or transactions. Navigation always goes to the server with `cache: no-store`; network failure returns only the generic offline message. Non-public API requests pass directly to the network. There is no background sync, IndexedDB or offline write queue. Operational data needs the running server; a failed request is not queued to be submitted later.

Apache sends `Cache-Control: no-store` on application responses. Existing authentication continues using normal HttpOnly/SameSite session cookies; HTTPS-issued cookies now also have **Secure**. The service worker never stores those cookies or login responses. Data is still stored in the existing MySQL database.

The existing UI, forms and modules stay online and unchanged. Existing external font/chart/scanner libraries continue to use their existing CDN URLs; this is not a full offline inventory application.

## Automated checks

From the project directory:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\pwa_check.ps1 -BaseUrl "https://192.168.1.4:8443/tooltrack/"
C:\xampp\php\php.exe tests\damage_loss_workflow.php
```

The PWA test starts an isolated Chrome profile, uses the real trusted HTTPS server **without** certificate bypass flags, and checks installability, manifest, actual icon dimensions, worker scope, authentication, no-store headers, the public-only cache allowlist, rejected offline transactions and the generic offline fallback. It closes its own test browser. It does not log in or change real records.

The existing PHP suite creates and removes a temporary test database. It verifies Borrow/Return, Missing/Damage & Loss charges, SKU and Student/CSV workflows.

## Manual device checks

- **Installation:** app has the custom royal-purple TT/FPST icon and opens without browser tabs/address bar.
- **Login/permissions:** log in using the usual account. Confirm staff/admin permissions match the browser version.
- **Inventory:** use a designated test tool/borrower to check a borrow, partial mixed-condition return and history. Refresh another online device to confirm the server's actual result.
- **Privacy:** DevTools → Application → Cache Storage should show only `fpst-tooltrack-public-v1` with the three public files above. Visiting Borrowers, reports or login must not add those responses to this cache.
- **Offline:** disconnect the test device's Wi-Fi and navigate/reload. Expect **ToolTrack is offline** rather than cached student records. No transaction should be queued. Reconnect before making changes. If an existing request lost its connection after submission, check its history before retrying.
- **Logout:** log out and relaunch; protected APIs/pages still require authentication.
- **LAN connectivity:** the phone must reach `192.168.1.4:8443`. Apache/MySQL and the server computer must stay on. No cloud hosting is involved.

Optional Windows curl diagnostic:

```powershell
curl.exe --noproxy "*" --ssl-no-revoke -I "https://192.168.1.4:8443/tooltrack/manifest.webmanifest"
```

Windows curl/Schannel may otherwise report `CRYPT_E_NO_REVOCATION_CHECK` because this private local CA has no online revocation service. `--ssl-no-revoke` disables that revocation lookup for this diagnostic only; certificate-chain and hostname validation remain enabled. No browser certificate bypass is needed or configured.

## HTTPS maintenance / another installation

The reviewed setup script is `pwa/setup-https.ps1`. Its execution-policy override is process-only; it does not change Windows' persistent policy.

On the XAMPP server, use an elevated PowerShell when configuring the firewall or restarting Apache:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\pwa\setup-https.ps1 -ServerIp 192.168.1.4 -Port 8443 -TrustCurrentUser -ConfigureFirewall -RestartApache
```

Run with `-TrustCurrentUser` as the Windows user who will use the app, or import the public CA manually for other Windows users. The firewall rule added by the script allows TCP 8443 on the **Private** profile from **LocalSubnet**. Existing Apache rules were not removed. If the Wi-Fi network profile is Public, switch a trusted school network to Private or have the administrator adjust the scoped firewall rule.

Configuration locations:

- `C:\xampp\apache\conf\extra\httpd-tooltrack-pwa.conf` — the dedicated HTTPS virtual host.
- `C:\xampp\apache\conf\httpd.conf` — one include for the dedicated configuration. The original has a `.tooltrack-pwa.bak` backup.
- `C:\xampp\apache\conf\ssl.tooltrack\` — CA/server certificate files and **private keys**, outside `htdocs`, with restricted Windows ACLs.
- `pwa/https-info.json` — public URL and certificate fingerprint metadata.

The server certificate lasts 365 days. Rerun setup before expiry to renew it; the existing CA is reused, so devices keep their CA trust. Preserve the private CA/server keys in a protected server backup; never put keys in `htdocs`, send them to students or include them in QR labels. Only the public `.cer` is distributed.

If the LAN IP changes, rerun setup with the new address to issue a matching certificate SAN. Update the URL and reinstall existing app shortcuts if needed; a changed host/IP or port is a different browser origin. A router DHCP reservation avoids that disruption. `tooltrack.local` is included in the certificate but needs your own LAN DNS; the IP URL requires no custom DNS.

To disable this HTTPS endpoint, remove/comment only `Include conf/extra/httpd-tooltrack-pwa.conf` from Apache's main configuration, validate with `C:\xampp\apache\bin\httpd.exe -t`, and restart Apache. Do not restore an old main configuration over unrelated later edits. Remove firewall rule `FPSTToolTrackHTTPS-8443` and the **FPST ToolTrack Local CA** trust only when no devices use this installation anymore.

To update the offline page/icons, increment the public-cache version in `sw.js`. The app does not force a reload or cache operational scripts/data when a worker update activates.

## Reference documentation

- [MDN: Making PWAs installable](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable)
- [Microsoft: Use PWAs in Edge](https://learn.microsoft.com/en-us/microsoft-edge/progressive-web-apps/ux)
- [Google: Add and remove Android certificates](https://support.google.com/pixelphone/answer/2844832/add-amp-remove-certificates) (device labels vary on Redmi).