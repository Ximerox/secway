/*
 * SecWay „Sicher versenden?" — OnMessageSend-Handler.
 *
 * Beim Senden: sammelt Betreff, Klartext-Body und Anhang-DATEINAMEN (keine
 * Bilder, keine Anhangsinhalte) sowie die Empfängeradressen und fragt SecWay.
 * Stuft SecWay die Mail als möglicherweise vertraulich ein, wird der Versand
 * mit einer Hinweismeldung angehalten (Outlooks eingebaute „Smart Alerts"-
 * Rückfrage: „Trotzdem senden" / „Nicht senden"). Ein EIGENER Dialog ist im
 * Sende-Ereignis des neuen Outlook nicht möglich (displayDialogAsync scheitert
 * mit Fehler 9032 — am 10.07.2026 nachgewiesen).
 *
 * Ist SecWay nicht erreichbar oder unauffällig, geht die Mail ohne Hinweis
 * hinaus. Dieses Add-in erzwingt nichts — es erinnert nur.
 *
 * Konfiguration: die beiden Werte unten anpassen. TOKEN = MGW_CLASSIFY_TOKEN
 * aus der SecWay-.env (liegt clientseitig; die API liefert nur ein Ja/Nein und
 * protokolliert keine Mailinhalte).
 */
const SECWAY_URL = "https://mailgateway.straphael.de";
const SECWAY_TOKEN = "REPLACE-WITH-MGW_CLASSIFY_TOKEN";
// Beim Deploy mit dem aktuellen Betreff-Tag ersetzt; dient als Rückfall,
// falls der Live-Abruf des Tags scheitert.
const SECWAY_TAG_FALLBACK = "REPLACE-WITH-SUBJECT-TAG";

function onMessageSendHandler(event) {
    const item = Office.context.mailbox.item;

    // Sicherheitsnetz NUR für Einsammeln + Klassifizieren (Office-Callbacks,
    // API-Antwort): nach spätestens 8 s wird normal gesendet, damit ein Hänger
    // nie zu Outlooks Endlos-„verarbeitet" führt. Sobald die Entscheidung fällt
    // (senden oder anhalten), wird der Wächter abgeschaltet.
    let done = false;
    function finish(opts) {
        if (done) return;
        done = true;
        event.completed(opts);
    }
    let watchdog = setTimeout(function () { finish({ allowEvent: true }); }, 8000);
    function disarm() { if (watchdog) { clearTimeout(watchdog); watchdog = null; } }
    function allow() { disarm(); finish({ allowEvent: true }); }

    // fail-open: bei jedem unerwarteten Fehler normal senden
    try {
        collect(item, function (payload) {
            classify(payload, function (verdict) {
                disarm();
                if (!verdict || !verdict.ask) {
                    finish({ allowEvent: true });
                    return;
                }
                // Möglicherweise vertraulich: Versand mit Hinweis anhalten.
                // sendModeOverride "promptUser" macht aus dem Manifest-SoftBlock
                // zur Laufzeit eine Rückfrage MIT „Trotzdem senden" (ab Mailbox
                // 1.14; ältere Clients bleiben beim SoftBlock = nur „Nicht senden").
                // „Trotzdem senden" = ungeschützt raus; „Nicht senden" = zurück
                // zum Entwurf, dann Betreff-Tag setzen und erneut senden.
                const tag = verdict.tag || "####";
                finish({
                    allowEvent: false,
                    sendModeOverride: "promptUser",
                    errorMessage:
                        "Diese Nachricht könnte vertrauliche Daten enthalten. " +
                        "Für einen gesicherten, verschlüsselten Versand wählen Sie „Nicht senden“, " +
                        "stellen Sie „" + tag + "“ an den Anfang des Betreffs und senden erneut. " +
                        "Ist kein Schutz nötig, wählen Sie „Trotzdem senden“."
                });
            });
        });
    } catch (e) {
        allow();
    }
}

/* Betreff + Body (Text) + Anhang-Namen + Empfänger einsammeln */
function collect(item, done) {
    const payload = { subject: "", body: "", attachments: [], recipients: [] };
    item.subject.getAsync(function (s) {
        payload.subject = (s.status === "succeeded" && s.value) ? s.value : "";
        item.body.getAsync(Office.CoercionType.Text, function (b) {
            payload.body = (b.status === "succeeded" && b.value) ? b.value : "";
            // WICHTIG: getAttachmentsAsync muss an item gebunden aufgerufen
            // werden — sonst geht der this-Kontext verloren und Office.js wirft
            // (der Callback käme nie zurück → Handler hinge). getAttachmentsAsync
            // gibt es erst ab Mailbox 1.8; sonst ohne Anhangsnamen fortfahren.
            function withAttachments(a) {
                if (a && a.status === "succeeded" && a.value) {
                    payload.attachments = a.value
                        .filter(function (att) { return !att.isInline; })
                        .map(function (att) { return att.name; });
                }
                gatherRecipients(item, function (rcpts) {
                    payload.recipients = rcpts;
                    done(payload);
                });
            }
            if (typeof item.getAttachmentsAsync === "function") {
                item.getAttachmentsAsync(withAttachments);
            } else {
                withAttachments({ status: "failed" });
            }
        });
    });
}

function gatherRecipients(item, done) {
    const out = [];
    item.to.getAsync(function (to) {
        if (to.status === "succeeded") to.value.forEach(function (r) { out.push(r.emailAddress); });
        item.cc.getAsync(function (cc) {
            if (cc.status === "succeeded") cc.value.forEach(function (r) { out.push(r.emailAddress); });
            item.bcc.getAsync(function (bcc) {
                if (bcc.status === "succeeded") bcc.value.forEach(function (r) { out.push(r.emailAddress); });
                done(out);
            });
        });
    });
}

/* SecWay fragen; jeder Fehler/Timeout => senden (verdict.ask=false) */
function classify(payload, done) {
    const ctrl = (typeof AbortController !== "undefined") ? new AbortController() : null;
    if (ctrl) setTimeout(function () { ctrl.abort(); }, 4000);
    fetch(SECWAY_URL + "/api/classify", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Authorization": "Bearer " + SECWAY_TOKEN },
        body: JSON.stringify(payload),
        signal: ctrl ? ctrl.signal : undefined
    })
        .then(function (r) { return r.ok ? r.json() : { ask: false }; })
        .then(function (j) { done(j); })
        .catch(function () { done({ ask: false }); });
}

/* --- Ribbon-Button „Sicher senden" --------------------------------------- *
 * Setzt das Betreff-Tag (falls noch nicht vorhanden) und sendet die Mail
 * direkt. Der Betreff-getAsync/setAsync-Callback MUSS vor sendAsync
 * abgeschlossen sein, sonst übernimmt Outlook die Betreffänderung evtl. nicht.
 * Nach sendAsync läuft kein Code mehr zuverlässig — daher passiert danach
 * nichts Wichtiges mehr außer event.completed().
 */
function secureSend(event) {
    const item = Office.context.mailbox.item;
    fetchTag(function (tag) {
        item.subject.getAsync(function (s) {
            const cur = (s.status === "succeeded" && s.value) ? s.value : "";
            const has = cur.toLowerCase().indexOf(tag.toLowerCase()) !== -1;
            const next = has ? cur : (tag + " " + cur);
            item.subject.setAsync(next, function (setRes) {
                if (setRes.status !== "succeeded") {
                    notify(item, "Betreff konnte nicht markiert werden — bitte manuell „" + tag + "“ voranstellen.");
                    event.completed();
                    return;
                }
                if (typeof item.sendAsync === "function") {
                    item.sendAsync(function () { try { event.completed(); } catch (e) {} });
                } else {
                    // Ältere Outlook-Version: Betreff ist markiert, Nutzer sendet selbst.
                    notify(item, "Als sicher markiert („" + tag + "“). Bitte jetzt senden.");
                    event.completed();
                }
            });
        });
    });
}

/* Aktuelles Tag vom Server; bei Fehler der beim Deploy injizierte Rückfall. */
function fetchTag(done) {
    const ctrl = (typeof AbortController !== "undefined") ? new AbortController() : null;
    if (ctrl) setTimeout(function () { ctrl.abort(); }, 4000);
    fetch(SECWAY_URL + "/api/subject-tag", {
        headers: { "Authorization": "Bearer " + SECWAY_TOKEN },
        signal: ctrl ? ctrl.signal : undefined
    })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) { done(j && j.tag ? j.tag : SECWAY_TAG_FALLBACK); })
        .catch(function () { done(SECWAY_TAG_FALLBACK); });
}

/* Info-Leiste im Entwurf (Fallback-Hinweise). */
function notify(item, text) {
    try {
        item.notificationMessages.addAsync("secway-secure", {
            type: "informationalMessage", message: text, icon: "none", persistent: false
        });
    } catch (e) { /* egal */ }
}

// Registrierung für das ereignisbasierte Runtime-Modell.
// WICHTIG: erst in Office.onReady registrieren — vorher ist der Ereignis-
// Dispatcher noch nicht bereit, die Zuordnung geht verloren und Outlook
// wartet beim Senden auf einen Handler, der nie anspringt (Timeout-Dialog).
// (Am 10.07.2026 per Diagnose-Beacons nachgewiesen.)
if (typeof Office !== "undefined" && Office.onReady) {
    Office.onReady(function () {
        Office.actions.associate("onMessageSendHandler", onMessageSendHandler);
        Office.actions.associate("secureSend", secureSend);
    });
}
