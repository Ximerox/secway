/*
 * SecWay Signatur — Compose-Add-in.
 *
 * Beim Verfassen (OnNewMessageCompose, auch Antworten/Weiterleiten) und bei
 * jeder Empfängeränderung (OnMessageRecipientsChanged): fragt SecWay nach dem
 * passenden Signaturblock (dieselbe Regel-Engine wie serverseitig), fügt ihn
 * per setSignatureAsync ein und markiert die Mail mit X-MGW-Signed — das
 * Gateway überspringt dann Signatur-Schritt und Postausgang-Tausch.
 *
 * Fail-safe: Bei Fehler/Timeout passiert NICHTS — dann hängt das Gateway den
 * Block wie bisher serverseitig an. Dieses Add-in hat keinen Send-Handler und
 * kann den Versand konstruktionsbedingt nie blockieren.
 *
 * Konfiguration: die beiden Werte unten anpassen (Token = MGW_SIGNATURE_TOKEN
 * aus der SecWay-.env; liegt clientseitig — die API liefert nur den fertigen
 * Signaturblock des angemeldeten Absenders).
 */
var SECWAY_URL = "https://secway.example.org";
var SECWAY_TOKEN = "REPLACE-WITH-MGW_SIGNATURE_TOKEN";

function onComposeHandler(event) {
    // Sicherheitsnetz: nach spätestens 8 s wird das Ereignis IMMER abgeschlossen.
    var done = false;
    function finish() {
        if (done) { return; }
        done = true;
        event.completed();
    }
    var watchdog = setTimeout(finish, 8000);
    function end() { clearTimeout(watchdog); finish(); }

    try {
        var item = Office.context.mailbox.item;
        var profile = Office.context.mailbox.userProfile;
        var sender = (profile && profile.emailAddress) ? profile.emailAddress : "";
        if (!sender || !item || !item.body) { end(); return; }

        gatherRecipients(item, function (recipients) {
            fetchSignature(sender, recipients, function (res) {
                if (!res) { end(); return; } // Fehler/Timeout: nichts tun, Gateway übernimmt

                if (res.none || !res.html) {
                    // Keine Regel passt: vorhandenen Block entfernen, Marker-Header weg
                    item.body.setSignatureAsync("", { coercionType: Office.CoercionType.Html }, function () {
                        removeHeader(item, end);
                    });
                    return;
                }

                item.body.setSignatureAsync(res.html, { coercionType: Office.CoercionType.Html }, function (r) {
                    if (r && r.status === "succeeded") {
                        setHeader(item, end); // Header NUR nach erfolgreichem Einfügen
                    } else {
                        end();
                    }
                });
            });
        });
    } catch (e) {
        end();
    }
}

/* Empfänger (to/cc/bcc) einsammeln — getAsync stets OBJEKTGEBUNDEN aufrufen! */
function gatherRecipients(item, done) {
    var out = [];
    function push(res) {
        if (res && res.status === "succeeded" && res.value) {
            res.value.forEach(function (r) { if (r.emailAddress) { out.push(r.emailAddress); } });
        }
    }
    item.to.getAsync(function (to) {
        push(to);
        item.cc.getAsync(function (cc) {
            push(cc);
            if (item.bcc && item.bcc.getAsync) {
                item.bcc.getAsync(function (bcc) { push(bcc); done(out); });
            } else {
                done(out);
            }
        });
    });
}

/* Signaturblock von SecWay holen; jeder Fehler => null (Gateway-Fallback). */
function fetchSignature(sender, recipients, done) {
    var ctrl = (typeof AbortController !== "undefined") ? new AbortController() : null;
    if (ctrl) { setTimeout(function () { ctrl.abort(); }, 4000); }
    fetch(SECWAY_URL + "/api/signature", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Authorization": "Bearer " + SECWAY_TOKEN },
        body: JSON.stringify({ sender: sender, recipients: recipients }),
        signal: ctrl ? ctrl.signal : undefined
    })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) { done(j); })
        .catch(function () { done(null); });
}

function setHeader(item, done) {
    if (item.internetHeaders && item.internetHeaders.setAsync) {
        item.internetHeaders.setAsync({ "X-MGW-Signed": "yes" }, function () { done(); });
    } else {
        done(); // Header nicht setzbar: Gateway signiert ggf. doppelt-sicher selbst
    }
}

function removeHeader(item, done) {
    if (item.internetHeaders && item.internetHeaders.removeAsync) {
        item.internetHeaders.removeAsync(["X-MGW-Signed"], function () { done(); });
    } else {
        done();
    }
}

// Registrierung für beide Compose-Ereignisse (ein Handler, gleiche Logik).
// WICHTIG: erst in Office.onReady registrieren — auf Modulebene ist der
// Ereignis-Dispatcher noch nicht bereit, die Zuordnung geht verloren und der
// Handler feuert nie (beim „Sicher versenden?"-Add-in am 10.07.2026 per
// Diagnose nachgewiesen — gilt für ALLE ereignisbasierten Handler).
if (typeof Office !== "undefined" && Office.onReady) {
    Office.onReady(function () {
        Office.actions.associate("onComposeHandler", onComposeHandler);
    });
}
