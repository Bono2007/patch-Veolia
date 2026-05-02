"use strict";

const crypto = require("crypto");
const fs = require("fs");
const path = require("path");

// ── FiSH base64 custom (ECB mode) ─────────────────────────────────────────────
const FISH_B64 = "./0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
const FISH_MAP = {};
for (let i = 0; i < FISH_B64.length; i++) FISH_MAP[FISH_B64[i]] = i;

function fishB64Decode(text) {
    const blocks = Math.floor(text.length / 12);
    const buf = Buffer.alloc(blocks * 8);
    for (let i = 0; i < blocks; i++) {
        let right = 0, left = 0;
        for (let j = 0; j < 6; j++) right |= (FISH_MAP[text[i * 12 + j]] || 0) << (j * 6);
        for (let j = 0; j < 6; j++) left  |= (FISH_MAP[text[i * 12 + 6 + j]] || 0) << (j * 6);
        buf.writeUInt32BE(left >>> 0, i * 8);
        buf.writeUInt32BE(right >>> 0, i * 8 + 4);
    }
    return buf;
}

function pad8(buf) {
    const rem = buf.length % 8;
    return rem === 0 ? buf : Buffer.concat([buf, Buffer.alloc(8 - rem)]);
}

// ── Déchiffrement ──────────────────────────────────────────────────────────────
function decryptCBC(key, b64) {
    try {
        const raw = Buffer.from(b64, "base64");
        if (raw.length < 9) return null;
        const d = crypto.createDecipheriv("bf-cbc", Buffer.from(key), raw.slice(0, 8));
        d.setAutoPadding(false);
        const plain = Buffer.concat([d.update(pad8(raw.slice(8))), d.final()]);
        return plain.toString("utf8").replace(/\0+$/, "").trim() || null;
    } catch { return null; }
}

function decryptECB(key, fishB64Str) {
    try {
        const raw = pad8(fishB64Decode(fishB64Str));
        if (raw.length < 8) return null;
        const d = crypto.createDecipheriv("bf-ecb", Buffer.from(key), Buffer.alloc(0));
        d.setAutoPadding(false);
        const plain = Buffer.concat([d.update(raw), d.final()]);
        return plain.toString("utf8").replace(/\0+$/, "").trim() || null;
    } catch { return null; }
}

const RE_CBC = /^\s*(?:\+OK|\*OK|mcps)\s+\*(.+)$/;
const RE_ECB = /^\s*(?:\+OK|\*OK|mcps)\s+([^*].*)$/;

function tryDecrypt(message, key) {
    let m;
    if ((m = RE_CBC.exec(message))) {
        const text = decryptCBC(key, m[1]);
        return text ? { text, mode: "CBC" } : null;
    }
    if ((m = RE_ECB.exec(message))) {
        const text = decryptECB(key, m[1]);
        return text ? { text, mode: "ECB" } : null;
    }
    return null;
}

// ── Stockage des clés  ("<networkUUID>_<channelname_lowercase>") ───────────────
let keys = {};
let keysFile = null;

function loadKeys(dir) {
    keysFile = path.join(dir, "fish-keys.json");
    if (fs.existsSync(keysFile)) {
        try { keys = JSON.parse(fs.readFileSync(keysFile, "utf8")); }
        catch { keys = {}; }
    }
}

function saveKeys() {
    if (keysFile) fs.writeFileSync(keysFile, JSON.stringify(keys, null, 2));
}

function keyFor(networkUuid, chanName) {
    return keys[`${networkUuid}_${chanName.toLowerCase()}`] ?? null;
}

function setKey(networkUuid, chanName, key) {
    keys[`${networkUuid}_${chanName.toLowerCase()}`] = key;
    saveKeys();
}

function delKey(networkUuid, chanName) {
    delete keys[`${networkUuid}_${chanName.toLowerCase()}`];
    saveKeys();
}

// ── Patch prototype Chan — actif dès le démarrage, sans action utilisateur ─────
// On cherche le module Chan dans le require cache de The Lounge (déjà chargé)
// et on patche son prototype une seule fois. Tous les channels existants et futurs
// bénéficient du déchiffrement automatiquement.
function patchChanPrototype() {
    const chanEntry = Object.values(require.cache)
        .find(m => m?.filename?.match(/thelounge.*[/\\]models[/\\]chan\.js$/));
    if (!chanEntry) return false;

    const Chan = chanEntry.exports?.default ?? chanEntry.exports;
    const proto = Chan?.prototype;
    if (!proto || typeof proto.pushMessage !== "function" || proto._fishPatched) return false;

    proto._fishPatched = true;
    const orig = proto.pushMessage;

    proto.pushMessage = function fishPushMessage(client, msg, ...rest) {
        if (msg?.text && client?.networks) {
            // Trouver le réseau auquel ce channel appartient
            const network = client.networks.find(n => {
                for (const ch of n.channels ?? []) if (ch === this) return true;
                return false;
            });
            if (network) {
                const key = keyFor(network.uuid, this.name);
                if (key) {
                    const result = tryDecrypt(msg.text, key);
                    if (result) msg.text = `\x0314[FiSH/${result.mode}]\x03 ${result.text}`;
                }
            }
        }
        return orig.call(this, client, msg, ...rest);
    };

    return true;
}

// ── Plugin entry point ─────────────────────────────────────────────────────────
module.exports = {
    onServerStart(api) {
        loadKeys(api.Config.getPersistentStorageDir());

        if (!patchChanPrototype()) {
            api.Logger.warn("FiSH: impossible de patcher Chan.prototype — le déchiffrement nécessitera /setkey à chaque session");
        }

        // /setkey [clé] — sans argument : affiche la clé courante
        api.Commands.add("setkey", {
            input(client, target, _cmd, args) {
                const { network, chan } = target;
                if (!args.length) {
                    const k = keyFor(network.uuid, chan.name);
                    client.sendMessage(
                        k ? `FiSH key pour ${chan.name} : ${k}` : `Pas de FiSH key pour ${chan.name}`,
                        chan
                    );
                    return;
                }
                setKey(network.uuid, chan.name, args[0]);
                client.sendMessage(`FiSH key définie pour ${chan.name}`, chan);
            },
            allowDisconnected: true,
        });

        // /rmkey — supprime la clé du channel courant
        api.Commands.add("rmkey", {
            input(client, target, _cmd, _args) {
                const { network, chan } = target;
                if (keyFor(network.uuid, chan.name)) {
                    delKey(network.uuid, chan.name);
                    client.sendMessage(`FiSH key supprimée pour ${chan.name}`, chan);
                } else {
                    client.sendMessage(`Pas de FiSH key pour ${chan.name}`, chan);
                }
            },
            allowDisconnected: true,
        });

        // /listkeys — liste tous les channels avec clé + valeur
        api.Commands.add("listkeys", {
            input(client, target, _cmd, _args) {
                const entries = Object.entries(keys);
                if (!entries.length) {
                    client.sendMessage("Aucune FiSH key configurée.", target.chan);
                    return;
                }
                client.sendMessage(`FiSH keys (${entries.length}) :`, target.chan);
                for (const [k, v] of entries) {
                    const chanPart = k.split("_").slice(1).join("_");
                    client.sendMessage(`  ${chanPart} → ${v}`, target.chan);
                }
            },
            allowDisconnected: true,
        });
    },
};
