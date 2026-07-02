<?php

return [

    // Aufbewahrung in Tagen, danach löscht mail:purge Nachricht + Anhänge
    'retention_days' => env('MGW_RETENTION_DAYS', 30),

    // Kennwort-Fehlversuche bis zur temporären Sperre
    'max_attempts' => env('MGW_MAX_ATTEMPTS', 5),
    'lockout_minutes' => env('MGW_LOCKOUT_MINUTES', 15),

    // Auslöse-Tag im Betreff, wird vor der Ablage entfernt
    'subject_tag' => env('MGW_SUBJECT_TAG', '[sicher]'),

    // Geheimnis, das die EXO-Transportregel als Header setzen muss.
    // Ohne gültigen Header wird eingehende Post abgewiesen (Bounce an Absender).
    'secret_header' => 'X-MGW-Auth',
    'ingest_secret' => env('MGW_INGEST_SECRET'),

];
