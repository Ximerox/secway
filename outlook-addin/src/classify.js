/*
 * SecWay „Sicher versenden?" — OnMessageSend-Handler.
 *
 * Beim Senden: sammelt Betreff, Klartext-Body und Anhang-DATEINAMEN (keine
 * Bilder, keine Anhangsinhalte) sowie die Empfängeradressen, fragt SecWay, und
 * bietet dem Absender bei sensiblen Mails an, „sicher" zu versenden (Tag im
 * Betreff). Ist SecWay nicht erreichbar, wird ohne Nachfrage gesendet.
 *
 * Konfiguration: die beiden Werte unten anpassen. TOKEN = MGW_CLASSIFY_TOKEN
 * aus der SecWay-.env (Hinweis: liegt clientseitig; für den Pilot vertretbar,
 * die API liefert nur ein Ja/Nein und protokolliert keine Inhalte).
 */
const SECWAY_URL = "https://mailgateway.straphael.de";
const SECWAY_TOKEN = "REPLACE-WITH-MGW_CLASSIFY_TOKEN";

function onMessageSendHandler(event) {
    const item = Office.context.mailbox.item;
    // fail-open: bei jedem unerwarteten Fehler normal senden
    try {
        collect(item, function (payload) {
            classify(payload, function (verdict) {
                if (!verdict || !verdict.ask) {
                    event.completed({ allowEvent: true });
                    return;
                }
                askUser(function (choice) {
                    reportChoice(verdict.logId, choice);
                    if (choice === "secure") {
                        const tag = verdict.tag || "####";
                        setTagThenSend(item, tag, event);
                    } else {
                        event.completed({ allowEvent: true });
                    }
                });
            });
        });
    } catch (e) {
        event.completed({ allowEvent: true });
    }
}

/* Betreff + Body (Text) + Anhang-Namen + Empfänger einsammeln */
function collect(item, done) {
    const payload = { subject: "", body: "", attachments: [], recipients: [] };
    item.subject.getAsync(function (s) {
        payload.subject = (s.status === "succeeded" && s.value) ? s.value : "";
        item.body.getAsync(Office.CoercionType.Text, function (b) {
            payload.body = (b.status === "succeeded" && b.value) ? b.value : "";
            (item.getAttachmentsAsync ? item.getAttachmentsAsync : function (cb) { cb({ status: "failed" }); })(function (a) {
                if (a.status === "succeeded" && a.value) {
                    payload.attachments = a.value
                        .filter(function (att) { return !att.isInline; })
                        .map(function (att) { return att.name; });
                }
                gatherRecipients(item, function (rcpts) {
                    payload.recipients = rcpts;
                    done(payload);
                });
            });
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
    const ctrl = ("AbortController" in window) ? new AbortController() : null;
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

function reportChoice(logId, choice) {
    if (!logId) return;
    fetch(SECWAY_URL + "/api/classify/" + logId + "/choice", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Authorization": "Bearer " + SECWAY_TOKEN },
        body: JSON.stringify({ choice: choice })
    }).catch(function () { /* Feedback ist optional */ });
}

/* Neutrale Ja/Nein-Frage per Dialog */
function askUser(done) {
    let answered = false;
    Office.context.ui.displayDialogAsync(
        SECWAY_URL + "/addin/dialog.html",
        { height: 32, width: 30, displayInIframe: true },
        function (res) {
            if (res.status !== "succeeded") { done("normal"); return; }
            const dlg = res.value;
            dlg.addEventHandler(Office.EventType.DialogMessageReceived, function (arg) {
                answered = true;
                dlg.close();
                done(arg.message === "secure" ? "secure" : "normal");
            });
            dlg.addEventHandler(Office.EventType.DialogEventReceived, function () {
                if (!answered) done("normal"); // Dialog vom Nutzer geschlossen
            });
        }
    );
}

/* Tag vorne in den Betreff setzen, dann senden */
function setTagThenSend(item, tag, event) {
    item.subject.getAsync(function (s) {
        const cur = (s.status === "succeeded" && s.value) ? s.value : "";
        const next = cur.indexOf(tag) === -1 ? (tag + " " + cur) : cur;
        item.subject.setAsync(next, function () { event.completed({ allowEvent: true }); });
    });
}

// Registrierung für das ereignisbasierte Runtime-Modell
if (typeof Office !== "undefined" && Office.actions && Office.actions.associate) {
    Office.actions.associate("onMessageSendHandler", onMessageSendHandler);
}
