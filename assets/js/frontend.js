/* global jQuery, wcPersonalizer */
(function ($) {
  'use strict';

  // ---------- helpers ----------
  function getProductId() {
    var m = (document.body.className || '').match(/postid-(\d+)/);
    return m ? parseInt(m[1], 10) : 0;
  }

  // Hide (do NOT remove) any existing preview layer the old plugin/theme added
  function hideLegacyPreviewLayers() {
    var style = document.createElement('style');
    // style.textContent = '.personalization-preview-element{opacity:0!important;pointer-events:none!important}';
    document.head.appendChild(style);
  }

  function slugId(prefix, any) {
    var s = (any == null) ? '' : String(any);
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
    var fam = (family == null) ? '' : String(family);
    if (!fam.trim()) return;
    var id = slugId('wcpp-gf-front', fam);
    if (document.getElementById(id)) return;

    var href = 'https://fonts.googleapis.com/css2?family=' +
      encodeURIComponent(fam).replace(/%20/g, '+') + '&display=swap';
    var link = document.createElement('link');
    link.id = id; link.rel = 'stylesheet'; link.href = href;
    document.head.appendChild(link);
  }

  // ---------- main ----------
  function mount() {
    hideLegacyPreviewLayers();

    var pid = getProductId();
    var img = document.querySelector('.woocommerce-product-gallery__image img') ||
      document.querySelector('.woocommerce-product-gallery__wrapper img');
    if (!img || !pid) return;

    // Canvas overlay on top of the gallery image
    var holder = img.closest('.woocommerce-product-gallery__image') || img.parentElement;
    if (getComputedStyle(holder).position === 'static') holder.style.position = 'relative';

    var cv = document.createElement('canvas');
    cv.id = 'wcpp-front-canvas';
    Object.assign(cv.style, { position: 'absolute', left: 0, top: 0, pointerEvents: 'none', zIndex: 9999 });
    holder.appendChild(cv);

    var ctx = cv.getContext('2d');
    ctx.textBaseline = 'top';

    // Cache for uploaded image previews (Blob URLs)
    var imageCache = {}; // { [elementId]: blobUrl }

    // Utilities to find inputs even if PHP naming differs
    function buildTypeIndex(elements) {
      var counts = { text: 0, image: 0, color: 0 };
      elements.forEach(function (el) { el.__orderIndex = counts[el.type]++; });
    }

    function queryWithin(container, sel) {
      return container ? container.querySelector(sel) : document.querySelector(sel);
    }

    function findInputForElement(el, elements) {
      var wrap = document.querySelector('.wc-personalizer-inputs') || document;
      // 1) Exact name match (preferred flow)
      var exact = queryWithin(wrap, '[name="wc_personalize[' + el.id + ']"]');
      if (exact) return exact;
      // 2) Data attribute commonly used
      var dataAttr = queryWithin(wrap, '[data-el-id="' + el.id + '"]');
      if (dataAttr) return dataAttr;
      // 3) Fallback by type & order
      var typeSel = el.type === 'text' ? 'input[type="text"], textarea'
        : el.type === 'image' ? 'input[type="file"]'
          : 'input[type="color"]';
      var list = Array.prototype.slice.call(wrap.querySelectorAll(typeSel));
      if (!list.length) return null;
      // choose the nth by order among same-type elements
      var nth = (typeof el.__orderIndex === 'number') ? el.__orderIndex : 0;
      return list[Math.min(nth, list.length - 1)] || null;
    }

    function bindTextInput(el, elements, onChange) {
      var input = findInputForElement(el, elements);
      if (!input || input.__wcppBound) return;
      input.__wcppBound = true;
      input.addEventListener('input', onChange);
      input.addEventListener('keyup', onChange);
    }

    function bindImageInput(el, elements, onChange) {
      var input = findInputForElement(el, elements);
      if (!input || input.__wcppBound) return;
      input.__wcppBound = true;

      var handle = function () {
        var file = input.files && input.files[0];
        if (!file) {
          if (imageCache[el.id]) URL.revokeObjectURL(imageCache[el.id]);
          imageCache[el.id] = null;
          onChange();
          return;
        }
        if (imageCache[el.id]) URL.revokeObjectURL(imageCache[el.id]);
        imageCache[el.id] = URL.createObjectURL(file);
        onChange();
      };
      input.addEventListener('change', handle);
      input.addEventListener('input', handle);
    }

    $.post(wcPersonalizer.ajax_url, {
      action: 'wc_personalizer_get_product_data',
      nonce: wcPersonalizer.nonce,
      product_id: pid
    }).done(function (resp) {
      if (!resp || !resp.success) return;
      var elements = resp.data.elements || [];
      window.wcppElements = elements;
      if (!elements.length) return;

      buildTypeIndex(elements); // so fallback-by-order works

      // Inject Text Style controls (uses admin's allowedFonts)
      elements.forEach(function (el) {
        if (el.type !== 'text') return;
        var panel = document.querySelector('.wc-personalizer-inputs') || document.querySelector('.summary');
        if (!panel) return;

        // If already injected for this element, skip
        if (panel.querySelector('[data-wcpp-style-for="' + el.id + '"]')) return;

        var grp = document.createElement('div');
        grp.className = 'personalization-input-group personalization-text-style';
        grp.setAttribute('data-wcpp-style-for', el.id);

        var label = document.createElement('div');
        label.textContent = 'Text Style';
        // label.style.fontWeight = '600';
        // label.style.margin = '6px 0 4px';
        grp.appendChild(label);

        // Family
        var famSel = document.createElement('select'); famSel.style.width = '100%';
        var allowed = (el.properties && el.properties.allowedFonts ? el.properties.allowedFonts.split(',') : ['Poppins', 'Roboto', 'Lato'])
          .map(function (s) { return s.trim(); }).filter(Boolean);
        var defFam = (el.properties && el.properties.fontFamily) || allowed[0] || 'Poppins';
        allowed.forEach(function (f) { ensureFontLink(f); var o = document.createElement('option'); o.value = f; o.textContent = f; if (f === defFam) o.selected = true; famSel.appendChild(o); });
        var hF = document.createElement('input'); hF.type = 'hidden'; hF.name = 'wc_personalize_style[' + el.id + '][fontFamily]'; hF.value = defFam;
        grp.appendChild(famSel); grp.appendChild(hF);

        // Size
        var size = document.createElement('input'); size.type = 'number'; size.min = 8; size.max = 120; size.value = (el.properties && el.properties.fontSizePx) || 24;
        size.style.width = '100%'; size.style.marginTop = '6px';
        var hS = document.createElement('input'); hS.type = 'hidden'; hS.name = 'wc_personalize_style[' + el.id + '][fontSize]'; hS.value = size.value;
        grp.appendChild(size); grp.appendChild(hS);

        // Color
        var color = document.createElement('input'); color.type = 'color'; color.value = (el.properties && el.properties.defaultColor) || '#000000';
        color.style.width = '100%'; color.style.marginTop = '6px';
        var hC = document.createElement('input'); hC.type = 'hidden'; hC.name = 'wc_personalize_style[' + el.id + '][color]'; hC.value = color.value;
        grp.appendChild(color); grp.appendChild(hC);

        panel.appendChild(grp);

        famSel.addEventListener('change', function () { ensureFontLink(this.value); hF.value = this.value; drawAll(); });
        size.addEventListener('input', function () { hS.value = this.value; drawAll(); });
        color.addEventListener('input', function () { hC.value = this.value; drawAll(); });
      });

      // Bind inputs now
      elements.forEach(function (el) {
        if (el.type === 'text') bindTextInput(el, elements, drawAll);
        if (el.type === 'image') bindImageInput(el, elements, drawAll);
        if (el.type === 'color') {
          // color input may be provided by PHP or not; bind if present
          var colorInput = findInputForElement(el, elements);
          if (colorInput && !colorInput.__wcppBound) {
            colorInput.__wcppBound = true;
            colorInput.addEventListener('input', drawAll);
            colorInput.addEventListener('change', drawAll);
          }
        }
      });

      // ---- DRAW with z-order: color (bottom) → image → text (top) ----
      function drawAll() {
        cv.width = img.clientWidth;
        cv.height = img.clientHeight;
        ctx.textBaseline = 'top';
        ctx.globalCompositeOperation = 'source-over';

        var bg = new Image();
        bg.crossOrigin = 'anonymous';
        bg.src = img.currentSrc || img.src;
        bg.onload = function () {
          ctx.clearRect(0, 0, cv.width, cv.height);
          ctx.drawImage(bg, 0, 0, bg.width, bg.height, 0, 0, cv.width, cv.height);

          // Utility to scale an element rect
          function rectScaled(el) {
            var refW = (el.properties && el.properties.refW) || 600;
            var refH = (el.properties && el.properties.refH) || 600;
            var sx = cv.width / refW, sy = cv.height / refH;
            return { x: el.x * sx, y: el.y * sy, w: el.width * sx, h: el.height * sy };
          }

          // 1) COLORS
          elements.filter(function (e) { return e.type === 'color'; }).forEach(function (el) {
            var r = rectScaled(el);
            ctx.save();
            var input = findInputForElement(el, elements);
            var cval = (input && input.value) || (el.properties && el.properties.defaultColor) || '#000';
            ctx.fillStyle = cval;
            ctx.fillRect(r.x, r.y, r.w, r.h);
            ctx.restore();
          });

          // 2) IMAGES
          elements.filter(function (e) { return e.type === 'image'; }).forEach(function (el) {
            var r = rectScaled(el);
            ctx.save();
            // Re-bind in case theme changed DOM
            bindImageInput(el, elements, drawAll);
            if (imageCache[el.id]) {
              var im = new Image();
              im.onload = function () {
                var sc = Math.min(r.w / im.width, r.h / im.height);
                var dw = im.width * sc, dh = im.height * sc;
                ctx.drawImage(im, 0, 0, im.width, im.height, r.x, r.y, dw, dh);
              };
              im.src = imageCache[el.id];
            } else {
              ctx.setLineDash([6, 4]); ctx.strokeStyle = '#00a0d2';
              ctx.strokeRect(r.x, r.y, r.w, r.h);
            }
            ctx.restore();
          });

          // 3) TEXT
          elements.filter(function (e) { return e.type === 'text'; }).forEach(function (el) {
            var r = rectScaled(el);
            ctx.save();
            var tInput = findInputForElement(el, elements); // robust lookup
            var textVal = (tInput ? tInput.value : '') || (el.properties && el.properties.defaultText) || '';

            var famSel = document.querySelector('[name="wc_personalize_style[' + el.id + '][fontFamily]"]');
            var sizeSel = document.querySelector('[name="wc_personalize_style[' + el.id + '][fontSize]"]');
            var colSel = document.querySelector('[name="wc_personalize_style[' + el.id + '][color]"]');

            var family = famSel ? famSel.value : ((el.properties && el.properties.fontFamily) || 'Poppins');
            var fsize = sizeSel ? parseInt(sizeSel.value || 24, 10)
              : parseInt((el.properties && el.properties.fontSizePx) || 24, 10);
            var color = colSel ? colSel.value : ((el.properties && el.properties.defaultColor) || '#000');

            ensureFontLink(family);
            ctx.font = fsize + 'px "' + family + '"';
            ctx.fillStyle = color;
            ctx.fillText(textVal, r.x, r.y);
            ctx.restore();
          });
        };
      }

      // initial paint + keep in sync with resizes/DOM changes
      drawAll();
      var ro = new ResizeObserver(drawAll); ro.observe(img);
      var mo = new MutationObserver(function () {
        elements.forEach(function (el) {
          if (el.type === 'text') bindTextInput(el, elements, drawAll);
          if (el.type === 'image') bindImageInput(el, elements, drawAll);
          if (el.type === 'color') {
            var c = findInputForElement(el, elements);
            if (c && !c.__wcppBound) { c.__wcppBound = true; c.addEventListener('input', drawAll); c.addEventListener('change', drawAll); }
          }
        });
        drawAll();
      });
      mo.observe(document.body, { childList: true, subtree: true });
    });
  }

  function mountOverlay(img, popup = false) {
    hideLegacyPreviewLayers();

    var pid = getProductId();

    if (!img || !pid) return;

    // Canvas overlay on top of the gallery image
    var holder = img.parentElement;

    console.log(img);
    console.log(holder);
    console.log(img.parentElement.querySelector);

    if (getComputedStyle(holder).position === 'static') holder.style.position = 'relative';

    // if (img.parentElement.querySelector('#wcpp-front-canvas')) {
    //   return;
    // }

    if (!popup) {
      return;
    }

    if (holder.querySelector('#wcpp-front-canvas')) {
      return; // canvas already exists
    }

    var cv = document.createElement('canvas');
    cv.id = 'wcpp-front-canvas';
    Object.assign(cv.style, { position: 'absolute', left: 0, top: 0, pointerEvents: 'none', zIndex: 9999 });
    holder.appendChild(cv);

    var ctx = cv.getContext('2d');
    ctx.textBaseline = 'top';

    // Cache for uploaded image previews (Blob URLs)
    var imageCache = {}; // { [elementId]: blobUrl }

    // Utilities to find inputs even if PHP naming differs
    function buildTypeIndex(elements) {
      var counts = { text: 0, image: 0, color: 0 };
      elements.forEach(function (el) { el.__orderIndex = counts[el.type]++; });
    }

    function queryWithin(container, sel) {
      return container ? container.querySelector(sel) : document.querySelector(sel);
    }

    function findInputForElement(el, elements) {
      var wrap = document.querySelector('.wc-personalizer-inputs') || document;
      // 1) Exact name match (preferred flow)
      var exact = queryWithin(wrap, '[name="wc_personalize[' + el.id + ']"]');
      if (exact) return exact;
      // 2) Data attribute commonly used
      var dataAttr = queryWithin(wrap, '[data-el-id="' + el.id + '"]');
      if (dataAttr) return dataAttr;
      // 3) Fallback by type & order
      var typeSel = el.type === 'text' ? 'input[type="text"], textarea'
        : el.type === 'image' ? 'input[type="file"]'
          : 'input[type="color"]';
      var list = Array.prototype.slice.call(wrap.querySelectorAll(typeSel));
      if (!list.length) return null;
      // choose the nth by order among same-type elements
      var nth = (typeof el.__orderIndex === 'number') ? el.__orderIndex : 0;
      return list[Math.min(nth, list.length - 1)] || null;
    }

    function bindTextInput(el, elements, onChange) {
      var input = findInputForElement(el, elements);
      if (!input || input.__wcppBound) return;
      input.__wcppBound = true;
      input.addEventListener('input', onChange);
      input.addEventListener('keyup', onChange);
    }

    function bindImageInput(el, elements, onChange) {
      var input = findInputForElement(el, elements);
      if (!input || input.__wcppBound) return;
      input.__wcppBound = true;

      var handle = function () {
        var file = input.files && input.files[0];
        if (!file) {
          if (imageCache[el.id]) URL.revokeObjectURL(imageCache[el.id]);
          imageCache[el.id] = null;
          onChange();
          return;
        }
        if (imageCache[el.id]) URL.revokeObjectURL(imageCache[el.id]);
        imageCache[el.id] = URL.createObjectURL(file);
        onChange();
      };
      input.addEventListener('change', handle);
      input.addEventListener('input', handle);
    }

    $.post(wcPersonalizer.ajax_url, {
      action: 'wc_personalizer_get_product_data',
      nonce: wcPersonalizer.nonce,
      product_id: pid
    }).done(function (resp) {
      // alert(resp)
      console.log("RESP: ", resp);
      if (!resp || !resp.success) return;
      var elements = resp.data.elements || [];
      // alert(elements)
      console.log("elements: ", elements);
      window.wcppElements = elements;
      if (!elements.length) return;

      buildTypeIndex(elements); // so fallback-by-order works

      // Inject Text Style controls (uses admin's allowedFonts)
      elements.forEach(function (el) {
        if (el.type !== 'text') return;
        var panel = document.querySelector('.wc-personalizer-inputs') || document.querySelector('.summary');
        if (!panel) return;

        // If already injected for this element, skip
        if (panel.querySelector('[data-wcpp-style-for="' + el.id + '"]')) return;

        var grp = document.createElement('div');
        grp.className = 'personalization-input-group personalization-text-style';
        grp.setAttribute('data-wcpp-style-for', el.id);

        var label = document.createElement('div');
        label.textContent = 'Text Style';
        // label.style.fontWeight = '600';
        // label.style.margin = '6px 0 4px';
        grp.appendChild(label);

        // Family
        var famSel = document.createElement('select'); famSel.style.width = '100%';
        var allowed = (el.properties && el.properties.allowedFonts ? el.properties.allowedFonts.split(',') : ['Poppins', 'Roboto', 'Lato'])
          .map(function (s) { return s.trim(); }).filter(Boolean);
        var defFam = (el.properties && el.properties.fontFamily) || allowed[0] || 'Poppins';
        allowed.forEach(function (f) { ensureFontLink(f); var o = document.createElement('option'); o.value = f; o.textContent = f; if (f === defFam) o.selected = true; famSel.appendChild(o); });
        var hF = document.createElement('input'); hF.type = 'hidden'; hF.name = 'wc_personalize_style[' + el.id + '][fontFamily]'; hF.value = defFam;
        grp.appendChild(famSel); grp.appendChild(hF);

        // Size
        var size = document.createElement('input'); size.type = 'number'; size.min = 8; size.max = 120; size.value = (el.properties && el.properties.fontSizePx) || 24;
        size.style.width = '100%'; size.style.marginTop = '6px';
        var hS = document.createElement('input'); hS.type = 'hidden'; hS.name = 'wc_personalize_style[' + el.id + '][fontSize]'; hS.value = size.value;
        grp.appendChild(size); grp.appendChild(hS);

        // Color
        var color = document.createElement('input'); color.type = 'color'; color.value = (el.properties && el.properties.defaultColor) || '#000000';
        color.style.width = '100%'; color.style.marginTop = '6px';
        var hC = document.createElement('input'); hC.type = 'hidden'; hC.name = 'wc_personalize_style[' + el.id + '][color]'; hC.value = color.value;
        grp.appendChild(color); grp.appendChild(hC);

        panel.appendChild(grp);

        famSel.addEventListener('change', function () { ensureFontLink(this.value); hF.value = this.value; drawAll(); });
        size.addEventListener('input', function () { hS.value = this.value; drawAll(); });
        color.addEventListener('input', function () { hC.value = this.value; drawAll(); });
      });

      // Bind inputs now
      elements.forEach(function (el) {
        if (el.type === 'text') bindTextInput(el, elements, drawAll);
        if (el.type === 'image') bindImageInput(el, elements, drawAll);
        if (el.type === 'color') {
          // color input may be provided by PHP or not; bind if present
          var colorInput = findInputForElement(el, elements);
          if (colorInput && !colorInput.__wcppBound) {
            colorInput.__wcppBound = true;
            colorInput.addEventListener('input', drawAll);
            colorInput.addEventListener('change', drawAll);
          }
        }
      });

      // ---- DRAW with z-order: color (bottom) → image → text (top) ----
      function drawAll() {
        cv.width = img.clientWidth;
        cv.height = img.clientHeight;
        ctx.textBaseline = 'top';
        ctx.globalCompositeOperation = 'source-over';

        var bg = new Image();
        bg.crossOrigin = 'anonymous';
        bg.src = img.currentSrc || img.src;
        bg.onload = function () {
          ctx.clearRect(0, 0, cv.width, cv.height);
          ctx.drawImage(bg, 0, 0, bg.width, bg.height, 0, 0, cv.width, cv.height);

          // Utility to scale an element rect
          function rectScaled(el) {
            var refW = (el.properties && el.properties.refW) || 600;
            var refH = (el.properties && el.properties.refH) || 600;
            var sx = cv.width / refW, sy = cv.height / refH;
            return { x: el.x * sx, y: el.y * sy, w: el.width * sx, h: el.height * sy };
          }

          // 1) COLORS
          elements.filter(function (e) { return e.type === 'color'; }).forEach(function (el) {
            var r = rectScaled(el);
            ctx.save();
            var input = findInputForElement(el, elements);
            var cval = (input && input.value) || (el.properties && el.properties.defaultColor) || '#000';
            ctx.fillStyle = cval;
            ctx.fillRect(r.x, r.y, r.w, r.h);
            ctx.restore();
          });

          // 2) IMAGES
          elements.filter(function (e) { return e.type === 'image'; }).forEach(function (el) {
            var r = rectScaled(el);
            ctx.save();
            // Re-bind in case theme changed DOM
            bindImageInput(el, elements, drawAll);
            if (imageCache[el.id]) {
              var im = new Image();
              im.onload = function () {
                var sc = Math.min(r.w / im.width, r.h / im.height);
                var dw = im.width * sc, dh = im.height * sc;
                ctx.drawImage(im, 0, 0, im.width, im.height, r.x, r.y, dw, dh);
              };
              im.src = imageCache[el.id];
            } else {
              ctx.setLineDash([6, 4]); ctx.strokeStyle = '#00a0d2';
              ctx.strokeRect(r.x, r.y, r.w, r.h);
            }
            ctx.restore();
          });

          // 3) TEXT
          elements.filter(function (e) { return e.type === 'text'; }).forEach(function (el) {
            var r = rectScaled(el);
            ctx.save();
            var tInput = findInputForElement(el, elements); // robust lookup
            var textVal = (tInput ? tInput.value : '') || (el.properties && el.properties.defaultText) || '';

            var famSel = document.querySelector('[name="wc_personalize_style[' + el.id + '][fontFamily]"]');
            var sizeSel = document.querySelector('[name="wc_personalize_style[' + el.id + '][fontSize]"]');
            var colSel = document.querySelector('[name="wc_personalize_style[' + el.id + '][color]"]');

            var family = famSel ? famSel.value : ((el.properties && el.properties.fontFamily) || 'Poppins');
            var fsize = sizeSel ? parseInt(sizeSel.value || 24, 10)
              : parseInt((el.properties && el.properties.fontSizePx) || 24, 10);
            var color = colSel ? colSel.value : ((el.properties && el.properties.defaultColor) || '#000');

            ensureFontLink(family);
            ctx.font = fsize + 'px "' + family + '"';
            ctx.fillStyle = color;
            ctx.fillText(textVal, r.x, r.y);
            ctx.restore();
          });
        };
      }

      // initial paint + keep in sync with resizes/DOM changes
      drawAll();
      var ro = new ResizeObserver(drawAll); ro.observe(img);
      var mo = new MutationObserver(function () {
        elements.forEach(function (el) {
          if (el.type === 'text') bindTextInput(el, elements, drawAll);
          if (el.type === 'image') bindImageInput(el, elements, drawAll);
          if (el.type === 'color') {
            var c = findInputForElement(el, elements);
            if (c && !c.__wcppBound) { c.__wcppBound = true; c.addEventListener('input', drawAll); c.addEventListener('change', drawAll); }
          }
        });
        drawAll();
      });
      mo.observe(document.body, { childList: true, subtree: true });
    });
  }

  document.addEventListener("DOMContentLoaded", function () {

    var productImg =
      document.querySelector('.woocommerce-product-gallery__image img') ||
      document.querySelector('.woocommerce-product-gallery__wrapper img');

    if (productImg) {
      mountOverlay(productImg);
    }

  });

  document.addEventListener('change', function (e) {

    if (!e.target.classList.contains('file-upload')) return;

    const file = e.target.files[0];
    if (!file) return;

    const previewBox = e.target
      .closest('.wc-upload-box')
      .querySelector('.wc-image-preview');

    const reader = new FileReader();

    reader.onload = function (event) {
      previewBox.innerHTML =
        `<img src="${event.target.result}" alt="preview">`;
    };

    reader.readAsDataURL(file);
  });

  $(function () {
    // if (document.querySelector('.single-product')) mount();


    /* commnets added by narendra start */

    $('body').addClass('modal-open');

    $('body').removeClass('modal-open');

    $('#wc-customize-btn').on('click', function () {

      $('#wc-customize-modal').fadeIn(200, function () {

        // Get price
        // var priceHtml = $('.woocommerce-Price-amount .amount').html();
        var priceHtml = $('.woocommerce-Price-amount').closest('.wpr-product-price').html();

        // Put price inside popup
        $('#wc-customize-modal .popup-price').html(priceHtml);

        const popupImg = document.querySelector('#wc-customize-modal img');

        if (!popupImg) return;

        // If image already cached
        if (popupImg.complete) {

          mountOverlay(popupImg, true);

        } else {

          popupImg.onload = function () {
            mountOverlay(popupImg, true);
          };

        }

      });

    });

    $('.wc-close, .wc-modal-overlay').on('click', function () {

      $('#wc-customize-modal').fadeOut(200);

    });

    // $('button.single_add_to_cart_button').prop('disabled', true);
    $("button.single_add_to_cart_button").hide();

    $('#customize-btn').on('click', function () {

      $('#wc-customize-modal')
        .fadeIn(200);

    });

    $('.wc-close, .wc-modal-overlay').on('click', function () {

      $('#wc-customize-modal')
        .fadeOut(200);

    });

    $('#customize-btn').click(function () {
      $('body').addClass('modal-open');
    });

    $('.wc-close, .wc-modal-overlay').click(function () {
      $('body').removeClass('modal-open');
    });

    $('.wc-add-cart').on('click', function (e) {
      e.preventDefault();

      var cv = document.querySelector('#wc-customize-modal canvas');

      console.log('CV:', cv);

      if (cv) {
        var png = cv.toDataURL('image/png');
        $('input[name="wc_personalize_render"]').val(png);
      }

      $('input[name="wc_personalize_payload"]').val(JSON.stringify(buildPayload()));


      // Trigger real WooCommerce add to cart
      // setTimeout(function () {
      $('button.single_add_to_cart_button').trigger('click');
      // $('form.cart').trigger('submit');
      // $("button.single_add_to_cart_button").show();
      // }, 300);

      // Hide popup
      // $('#wc-customize-modal').fadeOut(250);

      // OPTIONAL: remove blur scroll lock
      $('body').removeClass('modal-open');


    });

    /* commnets added by narendra end */

  });

  function buildPayload() {
    // we reuse the variables from the working script when available:
    var out = { elements: [], canvas: {} };

    // var cv = document.getElementById('wcpp-front-canvas');
          var cv = document.querySelector('#wc-customize-modal canvas');
    if (cv) out.canvas = { width: cv.width, height: cv.height };

    // Pull the elements the page already loaded for preview
    if (window.wcppElements && Array.isArray(window.wcppElements)) {
      window.wcppElements.forEach(function (el) {
        var row = JSON.parse(JSON.stringify(el)); // shallow copy
        // attach current input values
        var textInput = document.querySelector('[name="wc_personalize[' + el.id + ']"]');
        var famSel = document.querySelector('[name="wc_personalize_style[' + el.id + '][fontFamily]"]');
        var sizeSel = document.querySelector('[name="wc_personalize_style[' + el.id + '][fontSize]"]');
        var colorSel = document.querySelector('[name="wc_personalize_style[' + el.id + '][color]"]');

        if (el.type === 'text') {
          row.value = {
            text: textInput ? textInput.value : (el.properties && el.properties.defaultText) || '',
            fontFamily: famSel ? famSel.value : (el.properties && el.properties.fontFamily) || 'Poppins',
            fontSize: sizeSel ? parseInt(sizeSel.value || 24, 10) : parseInt((el.properties && el.properties.fontSizePx) || 24, 10),
            color: colorSel ? colorSel.value : (el.properties && el.properties.defaultColor) || '#000000'
          };
        } else if (el.type === 'color') {
          row.value = {
            color: (textInput && textInput.value) || (el.properties && el.properties.defaultColor) || '#000000'
          };
        } else if (el.type === 'image') {
          // server will attach the actual uploaded file path via $_FILES
          row.value = { note: 'user image will be processed server-side' };
        }
        out.elements.push(row);
      });
    }
    return out;
  }


  // ===== PERSONALIZER ➜ submit payload & preview =====
  (function attachCartSubmitCapture() {
    // make sure the form will upload files if needed
    jQuery(function ($) {
      var $form = $('form.cart');
      if (!$form.length) return;

      // some themes remove enctype; enforce it
      $form.attr('enctype', 'multipart/form-data').attr('encoding', 'multipart/form-data');

      // ensure hidden fields exist
      function ensureHidden(name) {
        var $i = $form.find('input[name="' + name + '"]');
        if (!$i.length) {
          $i = $('<input type="hidden" />').attr('name', name).appendTo($form);
        }
        return $i;
      }

      var $render = ensureHidden('wc_personalize_render');   // base64 png from canvas
      var $payload = ensureHidden('wc_personalize_payload'); // JSON of element values & styles

      // Build a minimal payload the server can store alongside the image
      function buildPayload() {
        // we reuse the variables from the working script when available:
        var out = { elements: [], canvas: {} };

        // var cv = document.getElementById('wcpp-front-canvas');
          var cv = document.querySelector('#wc-customize-modal canvas');
        if (cv) out.canvas = { width: cv.width, height: cv.height };

        // Pull the elements the page already loaded for preview
        if (window.wcppElements && Array.isArray(window.wcppElements)) {
          window.wcppElements.forEach(function (el) {
            var row = JSON.parse(JSON.stringify(el)); // shallow copy
            // attach current input values
            var textInput = document.querySelector('[name="wc_personalize[' + el.id + ']"]');
            var famSel = document.querySelector('[name="wc_personalize_style[' + el.id + '][fontFamily]"]');
            var sizeSel = document.querySelector('[name="wc_personalize_style[' + el.id + '][fontSize]"]');
            var colorSel = document.querySelector('[name="wc_personalize_style[' + el.id + '][color]"]');

            if (el.type === 'text') {
              row.value = {
                text: textInput ? textInput.value : (el.properties && el.properties.defaultText) || '',
                fontFamily: famSel ? famSel.value : (el.properties && el.properties.fontFamily) || 'Poppins',
                fontSize: sizeSel ? parseInt(sizeSel.value || 24, 10) : parseInt((el.properties && el.properties.fontSizePx) || 24, 10),
                color: colorSel ? colorSel.value : (el.properties && el.properties.defaultColor) || '#000000'
              };
            } else if (el.type === 'color') {
              row.value = {
                color: (textInput && textInput.value) || (el.properties && el.properties.defaultColor) || '#000000'
              };
            } else if (el.type === 'image') {
              // server will attach the actual uploaded file path via $_FILES
              row.value = { note: 'user image will be processed server-side' };
            }
            out.elements.push(row);
          });
        }
        return out;
      }

      // expose elements globally once, so buildPayload() can read them
      // (hook where your AJAX loaded elements resolves)
      if (!window.__wcppElementsBound) {
        window.__wcppElementsBound = true;
        // try to copy from last AJAX resp the script created
        // If your script stores `elements` in a closure, add this line there:
        //   window.wcppElements = elements;
        // (We also try to grab them later via MutationObserver in your preview code.)
      }

      /*$form.on('submit', function () {
        try {
          // var cv = document.getElementById('wcpp-front-canvas');
          var cv = document.querySelector('#wc-customize-modal #wcpp-front-canvas');
          console.log('CV DATA: ', cv)
          if (cv) {
            // IMPORTANT: toDataURL must succeed (avoid cross-origin BGs)
            var png = cv.toDataURL('image/png');
            $render.val(png);
          }
        } catch (e) {
          // ignore; server will still store JSON even if preview fails
          console.warn('[WCPP] toDataURL failed:', e);
        }
        try {
          $payload.val(JSON.stringify(buildPayload()));
        } catch (e) {
          console.warn('[WCPP] buildPayload failed:', e);
        }
      });*/
    });
  })();

})(jQuery);