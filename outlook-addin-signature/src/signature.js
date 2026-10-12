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
 * Konfiguration: URL, Client-ID der Add-in-App und Tenant-ID unten anpassen
 * (docs/ANLEITUNG-ADDIN-SSO.md).
 */
var SECWAY_URL = "https://secway.example.org";
var ADDIN_CLIENT_ID = "REPLACE-WITH-ADDIN-CLIENT-ID";
var ADDIN_TENANT_ID = "REPLACE-WITH-TENANT-ID";
var ADDIN_NAME = "signature";

/*
 * Anmeldung per Office-SSO statt festem Token: Outlook holt ein Entra-Token des
 * angemeldeten Benutzers für die App „SecWay Outlook-Add-ins". Im Code steht
 * kein Geheimnis. Weg 1: Nested App Authentication (MSAL, msal-browser in der
 * HTML-Seite). Weg 2 (Rückfall, z. B. JavaScript-only-Laufzeit):
 * OfficeRuntime.auth.getAccessToken (braucht <WebApplicationInfo> im Manifest).
 * Im ereignisbasierten Modus sind keine Dialoge möglich, daher Anmelde-/
 * Zustimmungs-Prompts aus — die Admin-Zustimmung in Entra ist Pflicht.
 * Ohne Token: Verhalten wie bei nicht erreichbarem SecWay (fail-open).
 */
var ADDIN_SCOPE = SECWAY_URL.replace("https://", "api://") + "/" + ADDIN_CLIENT_ID + "/access_as_user";

/* Fehler (ohne Inhalte/Token) an den Server melden — fire-and-forget. */
function report(stage, err) {
    try {
        if (typeof fetch === "undefined") { return; }
        var diag = {};
        try { diag = Office.context.diagnostics || {}; } catch (e) {}
        fetch(SECWAY_URL + "/api/addin/diag", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                addin: ADDIN_NAME, stage: stage,
                code: err && (err.code || err.name) ? String(err.code || err.name).slice(0, 40) : "",
                message: err && err.message ? String(err.message).slice(0, 300) : String(err || "").slice(0, 300),
                host: String(diag.host || "").slice(0, 40), platform: String(diag.platform || "").slice(0, 40),
                version: String(diag.version || "").slice(0, 40)
            })
        }).catch(function () {});
    } catch (e) {}
}

/* Token: zuerst NAA, sonst Office-SSO; jeder Fehler => null (und Diagnose). */
function getToken(done) {
    getTokenNaa(function (t) {
        if (t) { done(t); return; }
        getTokenSso(done);
    });
}

var naaPca = null;
function getTokenNaa(done) {
    try {
        var supported = Office.context.requirements && Office.context.requirements.isSetSupported("NestedAppAuth", "1.1");
        if (!supported || typeof msal === "undefined" || !msal.createNestablePublicClientApplication) {
            done(null); return;
        }
        var ready = naaPca ? Promise.resolve(naaPca) : msal.createNestablePublicClientApplication({
            auth: { clientId: ADDIN_CLIENT_ID, authority: "https://login.microsoftonline.com/" + ADDIN_TENANT_ID }
        }).then(function (pca) { naaPca = pca; return pca; });
        ready
            .then(function (pca) { return pca.acquireTokenSilent({ scopes: [ADDIN_SCOPE] }); })
            .then(function (r) { done(r && r.accessToken ? r.accessToken : null); })
            .catch(function (e) { report("naa", e); done(null); });
    } catch (e) {
        report("naa-throw", e); done(null);
    }
}

function getTokenSso(done) {
    try {
        if (typeof OfficeRuntime === "undefined" || !OfficeRuntime.auth || !OfficeRuntime.auth.getAccessToken) {
            report("no-getAccessToken", null); done(null); return;
        }
        OfficeRuntime.auth.getAccessToken({ allowSignInPrompt: false, allowConsentPrompt: false, forMSGraphAccess: false })
            .then(function (t) { done(t || null); })
            .catch(function (e) { report("getAccessToken", e); done(null); });
    } catch (e) {
        report("getAccessToken-throw", e); done(null);
    }
}

function onComposeHandler(event) {
    // Outlook Classic (Windows): JavaScript-only-Laufzeit ohne fetch/Timer —
    // Handler würde crashen. Sofort abschließen; das Gateway hängt die
    // Signatur serverseitig an (Auffangnetz-Pfad, X-MGW-Signed fehlt ja).
    if (typeof fetch !== "function" || typeof setTimeout !== "function") {
        try { event.completed(); } catch (e) { /* egal */ }
        return;
    }
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
        if (!item || !item.body) { end(); return; }

        resolveSender(item, function (sender) {
            if (!sender) { end(); return; }
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
        });
    } catch (e) {
        end();
    }
}

/*
 * Tatsächlichen Absender bestimmen: das „Von"-Feld der Mail (item.from) —
 * entscheidend bei freigegebenen Postfächern/„Senden als", wo der angemeldete
 * Benutzer NICHT der Absender ist. Fällt auf das Benutzerprofil zurück, wenn
 * from nicht verfügbar ist (ältere Clients). Zusammen mit dem Manifest-Event
 * OnMessageFromChanged wird die Signatur beim Wechsel des Von-Felds getauscht.
 */
function resolveSender(item, done) {
    function fallback() {
        var profile = Office.context.mailbox.userProfile;
        done((profile && profile.emailAddress) ? profile.emailAddress : "");
    }
    try {
        if (item.from && item.from.getAsync) {
            item.from.getAsync(function (res) {
                if (res && res.status === "succeeded" && res.value && res.value.emailAddress) {
                    done(res.value.emailAddress);
                } else {
                    fallback();
                }
            });
        } else {
            fallback();
        }
    } catch (e) {
        fallback();
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
    getToken(function (token) {
        if (!token) { done(null); return; }
        var ctrl = (typeof AbortController !== "undefined") ? new AbortController() : null;
        if (ctrl) { setTimeout(function () { ctrl.abort(); }, 4000); }
        fetch(SECWAY_URL + "/api/signature", {
            method: "POST",
            headers: { "Content-Type": "application/json", "Authorization": "Bearer " + token },
            body: JSON.stringify({ sender: sender, recipients: recipients }),
            signal: ctrl ? ctrl.signal : undefined
        })
            .then(function (r) {
                if (!r.ok) { report("api-http", { code: String(r.status), message: "HTTP " + r.status }); return null; }
                return r.json();
            })
            .then(function (j) { done(j); })
            .catch(function (e) { report("api-fetch", e); done(null); });
    });
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
