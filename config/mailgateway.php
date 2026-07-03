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

    // Eigene (interne) Domains, kommagetrennt — Empfänger dieser Domains
    // gelten als eingehende Mail (Entschlüsselung/Signaturprüfung/Ernten)
    'internal_domains' => env('MGW_INTERNAL_DOMAINS', ''),

    // Geheimnis, das die EXO-Transportregel als Header setzen muss.
    // Ohne gültigen Header wird eingehende Post abgewiesen (Bounce an Absender).
    'secret_header' => 'X-MGW-Auth',
    'ingest_secret' => env('MGW_INGEST_SECRET'),

];
