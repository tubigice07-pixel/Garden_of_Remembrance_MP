// script.js - shared by checkout and receipt

// Utility: generate QR on checkout page
function initCheckout(){
  const merchant = {
    name: 'Demo Store',
    id: 'GCM123456',
    order: 'ORD-2026-001',
    amount: 100.00,
    mobile: '+63 912 345 6789'
  };

  const EXP = 10 * 60; // 10 minutes
  let expiresAt = Date.now() + EXP * 1000;
  let countdown = null;

  function pad(n){ return n<10? '0'+n : n; }
  function update(){
    const now = Date.now();
    const diff = Math.max(0, Math.floor((expiresAt - now)/1000));
    const mins = Math.floor(diff/60);
    const secs = diff%60;
    const el = document.getElementById('expiresIn');
    if(el) el.textContent = pad(mins)+':'+pad(secs);
    const ea = document.getElementById('expiresAt'); if(ea) ea.textContent = new Date(expiresAt).toLocaleString();
    if(diff<=0){ clearInterval(countdown); document.getElementById('payBtn').disabled = true; }
  }

  // Fill merchant info
  document.getElementById('merchantName').textContent = merchant.name;
  document.getElementById('merchantId').textContent = merchant.id;
  document.getElementById('orderNumber').textContent = merchant.order;
  document.getElementById('amountDue').textContent = 'PHP ' + merchant.amount.toFixed(2);

  // QR
  const payload = `GCASH://pay?mid=${encodeURIComponent(merchant.id)}&order=${encodeURIComponent(merchant.order)}&amount=${merchant.amount.toFixed(2)}`;
  // Responsive QR size (max 220px, min 120px)
  (function(){
    const el = document.getElementById('qrcode'); el.innerHTML = '';
    let size = Math.min(220, Math.floor(window.innerWidth * 0.6));
    size = Math.max(120, size);
    new QRCode(el, { text: payload, width: size, height: size, colorDark:'#0a6cff', colorLight:'#ffffff', correctLevel: QRCode.CorrectLevel.M });
    const child = el.querySelector('img, canvas'); if(child){ child.style.width = '100%'; child.style.height = 'auto'; }
  })();

  countdown = setInterval(update, 1000); update();

  // Pay handler: simulate payment, save to sessionStorage then redirect to receipt
  document.getElementById('payBtn').addEventListener('click', function(){
    if(Date.now()>expiresAt) return alert('Payment window expired');
    const btn = this; btn.disabled = true; btn.textContent = 'Processing...';

    // Ensure payment source is set (default to 'user' if not provided)
    if (!localStorage.getItem('paymentSource')) localStorage.setItem('paymentSource','user');

    // Show processing modal and then complete transaction
    if (window.showProcessingModal){
      showProcessingModal({duration:2200}).then(()=>{
        const ref = 'GC' + Date.now().toString().slice(-8) + Math.floor(Math.random()*900+100);
        const tx = {
          recipient: merchant.name,
          mobile: merchant.mobile,
          amount: merchant.amount.toFixed(2),
          total: merchant.amount.toFixed(2),
          reference: ref,
          datetime: new Date().toLocaleString()
        };
        sessionStorage.setItem('gcash_tx', JSON.stringify(tx));
        hideProcessingModal();
        window.location.href = 'receipt.html';
      });
    } else {
      // fallback (no modal available)
      setTimeout(()=>{
        const ref = 'GC' + Date.now().toString().slice(-8) + Math.floor(Math.random()*900+100);
        const tx = { recipient: merchant.name, mobile: merchant.mobile, amount: merchant.amount.toFixed(2), total: merchant.amount.toFixed(2), reference: ref, datetime: new Date().toLocaleString() };
        sessionStorage.setItem('gcash_tx', JSON.stringify(tx));
        window.location.href = 'receipt.html';
      }, 900);
    }
  });
}

// --- Processing modal (shared) ---
let __gcashModal = null;
let __gcashDisabledButtons = [];

function createProcessingModal(){
  if (__gcashModal) return __gcashModal;
  const wrap = document.createElement('div'); wrap.id = 'gcashProcessingModal'; wrap.className = 'gcash-modal';
  wrap.innerHTML = `
    <div class="gcash-modal-panel" role="dialog" aria-modal="true">
      <div class="gcash-spinner" aria-hidden="true"><div class="ring"></div></div>
      <div class="gcash-messages">
        <div class="gcash-message">Processing Payment...</div>
      </div>
    </div>
  `;
  document.body.appendChild(wrap);
  __gcashModal = wrap;
  return __gcashModal;
}

function disableAllButtons(){
  __gcashDisabledButtons = [];
  const buttons = Array.from(document.querySelectorAll('button'));
  buttons.forEach(b => { __gcashDisabledButtons.push({el:b, disabled:b.disabled}); b.disabled = true; });
}

function restoreAllButtons(){
  __gcashDisabledButtons.forEach(obj => { if(obj && obj.el) obj.el.disabled = !!obj.disabled; });
  __gcashDisabledButtons = [];
}

function showProcessingModal(opts){
  opts = opts || {}; const messages = opts.messages || ['Processing Payment...','Verifying Transaction...','Please wait...'];
  const duration = typeof opts.duration === 'number' ? opts.duration : 2200;
  const interval = Math.max(400, Math.floor(duration / (messages.length || 1)));
  return new Promise(resolve=>{
    const modal = createProcessingModal();
    modal.style.display = 'flex';
    // set first message
    const msgEl = modal.querySelector('.gcash-message'); if(msgEl) msgEl.textContent = messages[0] || '';
    disableAllButtons();
    // rotate messages
    let idx = 0;
    const t = setInterval(()=>{
      idx = (idx+1) % messages.length; if(msgEl) msgEl.textContent = messages[idx];
    }, interval);
    // finish after duration
    setTimeout(()=>{ clearInterval(t); resolve(); }, duration);
  });
}

function hideProcessingModal(){
  if(!__gcashModal) return; __gcashModal.style.display = 'none'; restoreAllButtons();
}

function initReceipt(){
  const raw = sessionStorage.getItem('gcash_tx');
  if(!raw){
    document.getElementById('recName').textContent = 'Demo Merchant';
    document.getElementById('recMobile').textContent = '+63 912 345 6789';
    document.getElementById('recAmount').textContent = 'PHP 100.00';
    document.getElementById('recTotal').textContent = 'PHP 100.00';
    return;
  }
  const tx = JSON.parse(raw);
  document.getElementById('recName').textContent = tx.recipient;
  document.getElementById('recMobile').textContent = tx.mobile;
  document.getElementById('recAmount').textContent = 'PHP ' + Number(tx.amount).toFixed(2);
  document.getElementById('recTotal').textContent = 'PHP ' + Number(tx.total).toFixed(2);
  document.getElementById('recRef').textContent = tx.reference;
  document.getElementById('recDate').textContent = tx.datetime;

  // download handler
  document.getElementById('downloadBtn').addEventListener('click', function(){
    const el = document.querySelector('.receipt-card');
    html2canvas(el).then(canvas =>{
      const a = document.createElement('a'); a.href = canvas.toDataURL('image/png'); a.download = (tx.reference || 'receipt') + '.png'; a.click();
    });
  });

  // share handler - Web Share API
  document.getElementById('shareBtn').addEventListener('click', function(){
    if(navigator.share){
      navigator.share({ title: 'Payment Receipt', text: `Ref: ${tx.reference}\nAmount: PHP ${tx.amount}`, url: window.location.href }).catch(()=>{});
    } else { alert('Share not supported on this device'); }
  });

  // Exit / Done button logic
  const exitBtn = document.getElementById('exitBtn');
  if (exitBtn){
    exitBtn.addEventListener('click', function(){
      const source = localStorage.getItem('paymentSource');
      // Map to local app paths (change if your routes differ)
      if (source === 'admin'){
        // prefer admin dashboard path if exists
        window.location.href = 'dashboard.php';
      } else {
        window.location.href = 'user_dashboard.php';
      }
    });
  }
}

// Load html2canvas dynamically for download
(function(){
  if(window.location.pathname.endsWith('receipt.html')){
    const s = document.createElement('script'); s.src='https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js'; document.head.appendChild(s);
    document.addEventListener('DOMContentLoaded', initReceipt);
  }
  if(window.location.pathname.endsWith('checkout.html')){
    document.addEventListener('DOMContentLoaded', initCheckout);
  }
})();