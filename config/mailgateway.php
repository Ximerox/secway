<?php

return [

    // Betreibername, wie ihn Empfänger sehen (Portal-Kopf, Mail-Absenderzeile).
    // Überschreibbar in den Admin-Einstellungen (operator_name).
    'operator_name' => env('MGW_OPERATOR_NAME', 'SecWay'),

    // Aufbewahrung in Tagen, danach löscht mail:purge Nachricht + Anhänge
    'retention_days' => env('MGW_RETENTION_DAYS', 30),

    // Kennwort-Fehlversuche bis zur temporären Sperre
    'max_attempts' => env('MGW_MAX_ATTEMPTS', 5),
    'lockout_minutes' => env('MGW_LOCKOUT_MINUTES', 15),

    // Zeitlicher Versatz (Minuten) zwischen Link-Mail und Kennwort-Mail.
    // 0 = beide sofort. Der Versand übernimmt der Scheduler (mail:send-passwords).
    'password_delay_minutes' => env('MGW_PASSWORD_DELAY_MINUTES', 2),

    // Erinnerung an nicht abgerufene Portalnachrichten nach X Stunden (0 = aus)
    'reminder_after_hours' => env('MGW_REMINDER_AFTER_HOURS', 0),

    // Auslöse-Tag im Betreff, wird vor der Ablage entfernt
    'subject_tag' => env('MGW_SUBJECT_TAG', '[sicher]'),

    // Portal-Antworten: externe Empfänger können auf die zugestellte Nachricht
    // antworten (Text + Anhänge, ClamAV-geprüft). Zustellung an den internen
    // Absender. Alle Werte in den Admin-Einstellungen überschreibbar.
    'reply_enabled' => env('MGW_REPLY_ENABLED', false),
    'reply_max_size_mb' => env('MGW_REPLY_MAX_SIZE_MB', 20),
    'reply_max_per_message' => env('MGW_REPLY_MAX_PER_MESSAGE', 5),

    // Eigene (interne) Domains, kommagetrennt — Empfänger dieser Domains
    // gelten als eingehende Mail (Entschlüsselung/Signaturprüfung/Ernten)
    'internal_domains' => env('MGW_INTERNAL_DOMAINS', ''),

    // Quarantäne für eingehende S/MIME-Mails ohne passenden eigenen Schlüssel:
    // zurückhalten + Admin benachrichtigen statt verschlüsselt zuzustellen.
    // Nach Ablauf der Frist wird unverändert zugestellt (nichts bleibt liegen).
    'inbound_hold_enabled' => env('MGW_INBOUND_HOLD_ENABLED', false),
    'inbound_hold_hours' => env('MGW_INBOUND_HOLD_HOURS', 72),

    // Empfänger für Anwendungs-Benachrichtigungen (z. B. Quarantäne).
    // Betriebsalarme der Ops-Skripte nutzen weiterhin ALERT_TO in /etc/secway.conf.
    'admin_notify_email' => env('MGW_ADMIN_NOTIFY', ''),

    // Geheimnis, das die EXO-Transportregel als Header setzen muss.
    // Ohne gültigen Header wird eingehende Post abgewiesen (Bounce an Absender).
    'secret_header' => 'X-MGW-Auth',
    'ingest_secret' => env('MGW_INGEST_SECRET'),

    // Microsoft Graph (Entra ID) für das Signatur-Modul: Client-Credentials-Flow,
    // Application-Berechtigung User.Read.All (+ GroupMember.Read.All für Gruppen,
    // Mail.ReadWrite für Postausgang-Aktualisierung). Sync via `php artisan entra:sync`.
    'graph' => [
        'tenant_id' => env('GRAPH_TENANT_ID'),
        'client_id' => env('GRAPH_CLIENT_ID'),
        'client_secret' => env('GRAPH_CLIENT_SECRET'),
    ],

    // PHP-CLI-Binary für isolierte Subprozesse (QR-Code-Erzeugung). In FPM ist
    // PHP_BINARY = php-fpm und damit ungeeignet, daher separat konfigurierbar.
    'php_binary' => env('MGW_PHP_BINARY', '/usr/bin/php'),

    // Bearer-Token für die Add-in-API /api/classify („Sicher versenden?").
    // Das Outlook-Add-in muss diesen Wert als Authorization: Bearer mitsenden.
    // Erzeugen z. B. mit: openssl rand -hex 32
    'classify_token' => env('MGW_CLASSIFY_TOKEN'),

    // Bearer-Token für das Compose-Add-in „SecWay Signatur" (/api/signature).
    // Bewusst getrennt vom Classify-Token: eines lässt sich rotieren/sperren,
    // ohne das andere Add-in zu stören.
    'signature_token' => env('MGW_SIGNATURE_TOKEN'),

    // Lokaler LLM-Klassifizierer (llama.cpp, OpenAI-kompatibel, nur localhost).
    // Wird von der Send-Regel vom Typ „llm" genutzt. Timeout knapp halten,
    // damit die /api/classify-Antwort im Zeitbudget des Add-ins bleibt.
    'llm_endpoint' => env('MGW_LLM_ENDPOINT', 'http://127.0.0.1:8081/v1/chat/completions'),
    'llm_timeout' => env('MGW_LLM_TIMEOUT', 3),

];
