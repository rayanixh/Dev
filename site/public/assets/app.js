/**
 * Wizard behaviour. The server is authoritative for price and stock:
 * this script only mirrors the choices in the summary and enables buttons.
 * With JavaScript off, the form still submits correctly.
 */
(() => {
  'use strict';

  const form = document.getElementById('order-form');
  if (!form) return;

  const currency = form.dataset.currency || '৳';
  const pkgRadios = Array.from(form.querySelectorAll('input[name="pkg_choice"]'));
  const pkgField = document.getElementById('field-package');
  const qtyField = document.getElementById('field-qty');
  const qtyInput = document.getElementById('qty-input');
  const uidInput = document.getElementById('uid-input');
  const sumLabel = document.getElementById('sum-label');
  const sumQty = document.getElementById('sum-qty');
  const sumTotal = document.getElementById('sum-total');
  const buyBtn = document.getElementById('buy-btn');
  const buyHint = document.getElementById('buy-hint');
  const steps = Array.from(form.querySelectorAll('.step'));

  const fmt = (n) => currency + Number(n).toLocaleString('en-US');

  let unitPrice = 0;

  const qty = () => {
    if (qtyInput) {
      const v = parseInt(qtyInput.value, 10);
      return Number.isFinite(v) && v > 0 ? Math.min(v, parseInt(qtyInput.max, 10) || 10) : 1;
    }
    return 1;
  };

  function setActiveStep(n) {
    steps.forEach((s) => {
      const on = Number(s.dataset.step) === n;
      s.classList.toggle('is-active', on);
    });
  }

  function refresh() {
    const picked = pkgRadios.find((r) => r.checked && !r.disabled);
    unitPrice = picked ? Number(picked.dataset.price) : 0;

    pkgField.value = picked ? picked.value : '';
    qtyField.value = String(qty());

    sumLabel.textContent = picked ? picked.dataset.label : '—';
    sumQty.textContent = String(qty());
    sumTotal.textContent = fmt(unitPrice * qty());

    // Required detail per product type.
    const detailOk = uidInput
      ? uidInput.value.trim().length > 0
      : true;

    const ready = Boolean(picked) && detailOk;
    if (buyBtn) {
      buyBtn.disabled = !ready;
    }
    if (buyHint) {
      buyHint.textContent = !picked
        ? 'Choose a package to continue.'
        : (!detailOk ? 'Enter your UID to continue.' : 'Ready when you are.');
    }

    if (!picked) setActiveStep(1);
    else if (!detailOk) setActiveStep(2);
    else setActiveStep(3);
  }

  pkgRadios.forEach((r) => r.addEventListener('change', refresh));
  if (qtyInput) qtyInput.addEventListener('input', refresh);
  if (uidInput) {
    uidInput.addEventListener('input', refresh);
    uidInput.addEventListener('blur', () => {
      uidInput.value = uidInput.value.trim();
      refresh();
    });
  }

  // Stop a disabled option from being submitted.
  form.addEventListener('submit', (e) => {
    if (!pkgField.value) {
      e.preventDefault();
      setActiveStep(1);
      return;
    }
    if (buyBtn) buyBtn.disabled = true; // prevents double-click duplicates
  });

  refresh();
})();
