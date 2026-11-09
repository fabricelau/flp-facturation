/* Fabrice Lau Production — Facturation
   Relie l'appli au serveur PHP : données, téléchargements et envoi d'e-mails. */
(function () {
  'use strict';
  var G = window.GPF;
  var cache = { config: {}, clients: {}, articles: {}, formules: {}, docs: {} };
  var version = -1, loaded = null, listeners = [];

  function api(action, body) {
    var opt = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-CSRF': G.nonce } };
    if (body) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    return fetch(G.rest + action, opt).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (r.status === 401) { alert(j.message || 'Session expirée. Reconnecte-toi.'); location.reload(); }
        if (!r.ok) { var e = new Error(j.message || ('Erreur ' + r.status)); e.status = r.status; throw e; }
        return j;
      });
    });
  }
  function clone(x) { return x == null ? x : JSON.parse(JSON.stringify(x)); }
  function snapDoc(v) { return { exists: v != null, data: function () { return clone(v); } }; }
  function snapCol(col) {
    var m = cache[col];
    return { docs: Object.keys(m).map(function (id) { return { id: id, data: function () { return clone(m[id]); } }; }) };
  }
  function emit() { listeners.forEach(function (l) { try { l.fire(); } catch (e) { console.error(e); } }); }
  function apply(j) {
    version = j.version;
    cache.config = { entreprise: j.config && j.config.entreprise ? j.config.entreprise : null };
    ['clients', 'articles', 'formules', 'docs'].forEach(function (c) {
      var m = {}; (j[c] || []).forEach(function (x) { m[x.id] = x.data; }); cache[c] = m;
    });
    if (j.relances && j.relances.length) setTimeout(function () {
      var t = document.getElementById('toast');
      if (t) { t.textContent = 'Relance automatique envoyée : ' + j.relances.join(', '); t.hidden = false; setTimeout(function () { t.hidden = true; }, 5000); }
    }, 1500);
  }
  function load() { return api('load').then(function (j) { apply(j); emit(); }); }
  function ensure() { if (!loaded) loaded = load(); return loaded; }

  /* changements faits depuis un autre appareil */
  setInterval(function () {
    if (document.hidden || version < 0) return;
    api('version').then(function (j) { if (j.version !== version) load(); }).catch(function () {});
  }, 20000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && version >= 0) api('version').then(function (j) { if (j.version !== version) load(); }).catch(function () {});
  });

  function after(j) { if (j && typeof j.version === 'number') version = j.version; }
  function write(action, body, undo) {
    emit();
    return api(action, body).then(after, function (e) { undo(); emit(); load().catch(function () {}); throw e; });
  }

  var db = {
    doc: function (path) {
      if (path !== 'config/entreprise') throw new Error('Chemin inconnu : ' + path);
      return {
        onSnapshot: function (cb, err) {
          var l = { fire: function () { cb(snapDoc(cache.config.entreprise)); } }; listeners.push(l);
          ensure().catch(err || function () {});
          if (version >= 0) setTimeout(l.fire, 0);
          return function () { listeners = listeners.filter(function (x) { return x !== l; }); };
        },
        set: function (data) {
          var prev = cache.config.entreprise; cache.config.entreprise = clone(data);
          return write('config', { data: data }, function () { cache.config.entreprise = prev; });
        }
      };
    },
    collection: function (col) {
      return {
        onSnapshot: function (cb, err) {
          var l = { fire: function () { cb(snapCol(col)); } }; listeners.push(l);
          ensure().catch(err || function () {});
          if (version >= 0) setTimeout(l.fire, 0);
          return function () { listeners = listeners.filter(function (x) { return x !== l; }); };
        },
        doc: function (id) {
          return {
            set: function (data) {
              var prev = cache[col][id]; cache[col][id] = clone(data);
              return write('put', { col: col, id: id, data: data }, function () { if (prev === undefined) delete cache[col][id]; else cache[col][id] = prev; });
            },
            delete: function () {
              var prev = cache[col][id]; delete cache[col][id];
              return write('delete', { col: col, id: id }, function () { if (prev !== undefined) cache[col][id] = prev; });
            }
          };
        }
      };
    }
  };

  var downloads = {
    save: function (o) {
      var blob = o.data instanceof Blob ? o.data : new Blob([o.data], { type: /\.csv$/i.test(o.filename) ? 'text/csv;charset=utf-8' : /\.json$/i.test(o.filename) ? 'application/json' : 'application/octet-stream' });
      var url = URL.createObjectURL(blob), a = document.createElement('a');
      a.href = url; a.download = o.filename || 'fichier'; a.rel = 'noopener';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
      return Promise.resolve({ ok: true });
    }
  };

  var mcp = {
    callTool: function (server, tool, args) {
      if (tool !== 'create_draft') return Promise.reject(new Error('Action non disponible'));
      return api('mail', args);
    }
  };

  window.claude = {
    use: function (name) {
      if (name === 'db') return Promise.resolve(db);
      if (name === 'downloads') return Promise.resolve(downloads);
      if (name === 'mcp') return Promise.resolve(mcp);
      return Promise.reject(new Error('indisponible'));
    }
  };
})();
