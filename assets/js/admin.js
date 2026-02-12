/* global jQuery, wcPersonalizer */
(function ($) {
  'use strict';

  var dlg = $('#wc-personalize-modal');
  var content = $('#wc-personalize-modal-content');
  var currentProductId = null;

  if (dlg.length) {
    dlg.dialog({
      autoOpen: false,
      modal: true,
      width: 1200,
      height: 700,
      title: 'Product Personalization Editor',
      buttons: {
        Save: function () { if (window.__WCPP_ADMIN__ && window.__WCPP_ADMIN__.save) window.__WCPP_ADMIN__.save(); },
        Close: function () { $(this).dialog('close'); }
      }
    });
  }

  // Open editor (flow unchanged)
  $(document).on('click', '.wc-personalize-edit-btn', function () {
    currentProductId = $(this).data('product-id');
    $.post(wcPersonalizer.ajax_url, {
      action: 'wc_personalizer_get_product_data',
      nonce: wcPersonalizer.nonce,
      product_id: currentProductId
    }).done(function (resp) {
      if (!resp || !resp.success) { alert('Failed to load product'); return; }
      render(resp.data);
    });
  });

  // ---------------------- Admin canvas editor ----------------------
  var CANVAS_W = 600, CANVAS_H = 600;

  var GOOGLE_FONTS = [
    'Poppins','Roboto','Open Sans','Montserrat','Lato','Nunito','Inter','Oswald','Playfair Display','Raleway',
    'Merriweather','Source Sans 3','Noto Sans','Ubuntu','Kanit','Rubik','Cabin','Quicksand','Josefin Sans',
    'Pacifico','Dancing Script','Fjalla One','Anton','Bebas Neue','Work Sans','Libre Baskerville','Arvo','Teko','Barlow'
  ];

  // --- SAFE helpers (no regex on non-strings, no recursion) ---
  function slugId(prefix, any) {
    var s = (any === undefined || any === null) ? '' : String(any);
    s = s.trim().toLowerCase();
    var out = '';
    for (var i = 0; i < s.length; i++) {
      var ch = s.charCodeAt(i), c = s[i];
      if (c === ' ') out += '-';
      else if ((ch >= 97 && ch <= 122) || (ch >= 48 && ch <= 57) || c === '-') out += c;
    }
    if (!out) out = 'font';
    return (prefix || 'wcpp') + '-' + out;
  }

  function ensureFontLink(family) {
    var fam = (family === undefined || family === null) ? '' : String(family);
    if (!fam.trim()) return;
    var id = slugId('wcpp-gf', fam);
    if (document.getElementById(id)) return;

    var href = 'https://fonts.googleapis.com/css2?family=' +
      encodeURIComponent(fam).replace(/%20/g, '+') + '&display=swap';

    var link = document.createElement('link');
    link.id = id; link.rel = 'stylesheet'; link.href = href;
    document.head.appendChild(link);
  }

  function render(product) {
    var html = '' +
    '<div id="wcpp-admin-app" class="wc-personalizer-editor">' +
      '<div class="editor-sidebar">' +
        '<h3>Add Elements</h3>' +
        '<div class="element-types">' +
          '<button class="element-type-btn" data-type="text">Text Box</button>' +
          '<button class="element-type-btn" data-type="image">Image</button>' +
          '<button class="element-type-btn" data-type="color">Color Picker</button>' +
        '</div>' +
        '<h3>Current Elements</h3>' +
        '<div id="wcpp-list" class="elements-list"></div>' +
      '</div>' +
      '<div class="editor-preview">' +
        '<div class="preview-container"><canvas id="wcpp-canvas" width="600" height="600"></canvas></div>' +
      '</div>' +
      '<div class="element-properties">' +
        '<h3>Element Properties</h3>' +
        '<div id="wcpp-props"><p>Select an element to edit its properties.</p></div>' +
      '</div>' +
    '</div>';

    content.html(html);
    dlg.dialog('open');

    var img = new Image();
    img.crossOrigin = 'anonymous';
    img.src = product.image_url || '';

    var c = document.getElementById('wcpp-canvas');
    var ctx = c.getContext('2d');
    ctx.textBaseline = 'top';

    var S = {
      elements: Array.isArray(product.elements) ? JSON.parse(JSON.stringify(product.elements)) : [],
      selectedId: null,
      dragging: null // {id, mode:'move'|'resize', offx, offy}
    };

    function clear() { ctx.clearRect(0,0,c.width,c.height); }
    function drawBG() {
      if (!img.width) return;
      var s = Math.min(CANVAS_W / img.width, CANVAS_H / img.height);
      ctx.drawImage(img, 0, 0, img.width, img.height, 0, 0, img.width * s, img.height * s);
    }
    function drawText(el) {
      var p = el.properties || (el.properties = {});
      var family = p.fontFamily || 'Poppins';
      ensureFontLink(family);
      var size = parseInt(p.fontSizePx || 24, 10);
      ctx.font = size + 'px "' + family + '"';
      ctx.fillStyle = p.defaultColor || '#000';
      var text = p.defaultText || 'Text';
      if (!el.width || !el.height) {
        var m = ctx.measureText(text);
        el.width  = Math.ceil(m.width + 8);
        el.height = Math.ceil(size * 1.2);
      }
      ctx.fillText(text, el.x, el.y);
    }
    function drawElement(el) {
      if (el.type === 'text') drawText(el);
      else if (el.type === 'color') {
        ctx.fillStyle = (el.properties && el.properties.defaultColor) || '#000';
        ctx.fillRect(el.x, el.y, el.width, el.height);
      } else if (el.type === 'image') {
        ctx.setLineDash([6,4]); ctx.strokeStyle = '#27ae60';
        ctx.strokeRect(el.x, el.y, el.width, el.height);
        ctx.setLineDash([]);
      }
      if (S.selectedId === el.id) {
        ctx.strokeStyle = '#1e90ff'; ctx.lineWidth = 1;
        ctx.strokeRect(el.x, el.y, el.width, el.height);
        ctx.fillStyle = '#1e90ff';
        ctx.fillRect(el.x + el.width - 6, el.y + el.height - 6, 6, 6);
      }
    }

    // Canvas-only redraw (prevents UI recursion)
    function redrawCanvas() { clear(); drawBG(); (S.elements || []).forEach(drawElement); }

    // Full redraw (canvas + side UI)
    function redraw() {
      redrawCanvas();
      renderList(); renderProps();
    }

    // Hit testing / dragging
    function hit(x, y) {
      for (var i = S.elements.length - 1; i >= 0; i--) {
        var el = S.elements[i];
        if (x >= el.x && y >= el.y && x <= el.x + el.width && y <= el.y + el.height) {
          if (x >= el.x + el.width - 8 && y >= el.y + el.height - 8) return { id: el.id, mode: 'resize' };
          return { id: el.id, mode: 'move', offx: x - el.x, offy: y - el.y };
        }
      }
      return null;
    }
    c.addEventListener('mousedown', function (e) {
      var r = c.getBoundingClientRect(); var x = e.clientX - r.left, y = e.clientY - r.top;
      var h = hit(x, y);
      if (h) { S.selectedId = h.id; S.dragging = h; redraw(); }
      else { S.selectedId = null; S.dragging = null; redraw(); }
    });
    c.addEventListener('mousemove', function (e) {
      if (!S.dragging) return;
      var el = S.elements.find(function (x) { return x.id === S.dragging.id; });
      if (!el) return;
      var r = c.getBoundingClientRect(); var x = e.clientX - r.left, y = e.clientY - r.top;
      if (S.dragging.mode === 'move') {
        el.x = Math.max(0, Math.min(CANVAS_W - el.width,  x - S.dragging.offx));
        el.y = Math.max(0, Math.min(CANVAS_H - el.height, y - S.dragging.offy));
      } else {
        el.width  = Math.max(10, Math.min(CANVAS_W - el.x, x - el.x));
        el.height = Math.max(10, Math.min(CANVAS_H - el.y, y - el.y));
      }
      redrawCanvas(); // canvas only while dragging
    });
    window.addEventListener('mouseup', function () { S.dragging = null; });

    // Sidebar list
    function renderList() {
      var box = document.getElementById('wcpp-list'); if (!box) return;
      box.innerHTML = '';
      (S.elements || []).forEach(function (el) {
        var d = document.createElement('div');
        d.className = 'element-item' + (S.selectedId === el.id ? ' active' : '');
        d.textContent = (el.label || (el.type === 'text' ? 'Text' : el.type)) + ' (' + el.type + ')';
        d.onclick = function () { S.selectedId = el.id; redraw(); };
        box.appendChild(d);
      });
    }

    // Properties (no redraw() calls while building—use redrawCanvas() instead)
    function renderProps() {
      var wrap = document.getElementById('wcpp-props'); if (!wrap) return;
      var el = S.elements.find(function (x) { return x.id === S.selectedId; });
      if (!el) { wrap.innerHTML = '<p>Select an element to edit its properties.</p>'; return; }
      var p = el.properties || (el.properties = {});

      var html = '' +
      '<div class="property-group"><label>Label:</label>' +
        '<input type="text" id="p-label" value="' + (el.label || '') + '" class="property-input"/></div>';

      if (el.type === 'text') {
        html += '' +
        '<div class="property-group"><label>Default Text:</label>' +
          '<input type="text" id="p-text" value="' + (p.defaultText || '') + '" class="property-input"/></div>' +
        '<div class="property-group"><label>Default Color:</label>' +
          '<input type="color" id="p-color" value="' + (p.defaultColor || '#000000') + '" class="property-input"/></div>' +
        '<div class="property-group"><label>Allowed Google Fonts (max 5):</label>' +
          '<select id="p-gfonts" multiple size="8" class="property-input"></select>' +
          '<small>Pick up to 5 families shoppers can choose.</small></div>' +
        '<div class="property-group"><label>Default Font Family:</label>' +
          '<select id="p-font" class="property-input"></select></div>';
      }

      if (el.type === 'color') {
        html += '' +
        '<div class="property-group"><label>Default Color:</label>' +
          '<input type="color" id="p-color" value="' + (p.defaultColor || '#000000') + '" class="property-input"/></div>';
      }

      html += '' +
      '<div class="property-group"><label>Position (X, Y):</label>' +
        '<input type="number" id="p-x" value="' + (el.x || 0) + '" class="property-input small"/>' +
        '<input type="number" id="p-y" value="' + (el.y || 0) + '" class="property-input small"/></div>' +
      '<div class="property-group"><label>Size (W, H):</label>' +
        '<input type="number" id="p-w" value="' + (el.width || 100) + '" class="property-input small"/>' +
        '<input type="number" id="p-h" value="' + (el.height || 30) + '" class="property-input small"/></div>' +
      '<button class="button button-danger" id="p-del">Delete Element</button>';

      wrap.innerHTML = html;

      // Bindings (use redrawCanvas() to avoid UI recursion)
      wrap.querySelector('#p-label').oninput = function (e) { el.label = e.target.value; renderList(); };

      if (el.type === 'text') {
        wrap.querySelector('#p-text').oninput  = function (e) { p.defaultText  = e.target.value; redrawCanvas(); };
        wrap.querySelector('#p-color').oninput = function (e) { p.defaultColor = e.target.value; redrawCanvas(); };

        var selMulti = wrap.querySelector('#p-gfonts');
        var allowedInit = (p.allowedFonts ? p.allowedFonts.split(',').map(function (s) { return s.trim(); }) : ['Poppins','Roboto']).slice(0,5);
        GOOGLE_FONTS.forEach(function (f) {
          var opt = document.createElement('option'); opt.value = f; opt.textContent = f;
          if (allowedInit.indexOf(f) !== -1) opt.selected = true;
          selMulti.appendChild(opt);
        });
        selMulti.addEventListener('change', function () {
          var selected = Array.prototype.slice.call(this.selectedOptions).map(function (o) { return o.value; });
          if (selected.length > 5) {
            alert('Please select up to 5 fonts.');
            Array.prototype.forEach.call(this.options, function (o) {
              o.selected = selected.slice(0,5).indexOf(o.value) !== -1;
            });
            selected = selected.slice(0,5);
          }
          p.allowedFonts = selected.join(', ');
          buildDefaultFont(selected.length ? selected : allowedInit, true); // redraw canvas only
        });

        function buildDefaultFont(arr, doRedraw) {
          var sel = wrap.querySelector('#p-font');
          sel.innerHTML = '';
          (arr || []).forEach(function (f) {
            ensureFontLink(f);
            var o = document.createElement('option'); o.value = f; o.textContent = f; sel.appendChild(o);
          });
          var def = (p.fontFamily && arr.indexOf(p.fontFamily) !== -1) ? p.fontFamily : (arr[0] || 'Poppins');
          p.fontFamily = def; sel.value = def; ensureFontLink(def);
          if (doRedraw) redrawCanvas();
          sel.onchange = function () { p.fontFamily = this.value; ensureFontLink(this.value); redrawCanvas(); };
        }
        buildDefaultFont(allowedInit, false); // initial build: no redraw()
      }

      if (el.type === 'color' && wrap.querySelector('#p-color')) {
        wrap.querySelector('#p-color').oninput = function (e) { p.defaultColor = e.target.value; redrawCanvas(); };
      }

      wrap.querySelector('#p-x').oninput = function (e) { el.x = parseInt(e.target.value || 0, 10); redrawCanvas(); };
      wrap.querySelector('#p-y').oninput = function (e) { el.y = parseInt(e.target.value || 0, 10); redrawCanvas(); };
      wrap.querySelector('#p-w').oninput = function (e) { el.width  = Math.max(10, parseInt(e.target.value || 10, 10)); redrawCanvas(); };
      wrap.querySelector('#p-h').oninput = function (e) { el.height = Math.max(10, parseInt(e.target.value || 10, 10)); redrawCanvas(); };
      wrap.querySelector('#p-del').onclick   = function () {
        S.elements = S.elements.filter(function (x) { return x.id !== el.id; });
        S.selectedId = null; redraw();
      };
    }

    // Add element
    $('.element-type-btn').off('click').on('click', function () {
      var type = $(this).data('type');
      var el = {
        id: 'el_' + Date.now(),
        type: type,
        label: (type === 'text' ? 'Text' : (type === 'image' ? 'Image' : 'Color')),
        x: 50, y: 50,
        width: (type === 'image' ? 200 : 120),
        height: (type === 'image' ? 200 : 30),
        properties: {
          defaultText: (type === 'text' ? 'Text' : ''),
          defaultColor: '#000000',
          fontFamily: 'Poppins',
          allowedFonts: 'Poppins, Roboto',
          refW: CANVAS_W, refH: CANVAS_H
        }
      };
      S.elements.push(el); S.selectedId = el.id; redraw();
    });

    // Save
    window.__WCPP_ADMIN__ = {
      save: function () {
        S.elements.forEach(function (el) {
          el.properties = el.properties || {};
          el.properties.refW = CANVAS_W;
          el.properties.refH = CANVAS_H;
        });
        $.post(wcPersonalizer.ajax_url, {
          action: 'wc_personalizer_save_elements',
          nonce: wcPersonalizer.nonce,
          product_id: currentProductId,
          elements: S.elements
        }).done(function (r) {
          if (r && r.success) { alert('Saved'); dlg.dialog('close'); }
          else { alert('Save failed'); }
        });
      }
    };

    function firstPaint() {
      redraw(); // one full build on load
      if (document.fonts && document.fonts.ready) document.fonts.ready.then(redrawCanvas);
    }

    img.onload = firstPaint;
    if (img.complete) firstPaint();
  }
})(jQuery);
