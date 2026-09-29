<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Payment status | KITA</title><link rel="stylesheet" href="{{ asset('styles.css') }}"></head>
<body class="auth-page payment-page"><main class="auth-card"><div class="auth-card__bar"></div><div class="auth-card__body">
<img class="kita-logo" src="{{ asset('images/kita-logo.jpg') }}" alt="KITA - Money. Managed. Smarter." width="150" height="150"><h1 class="auth-title">Payment status</h1><p id="payment-status" role="status">Checking transaction status...</p>
<button id="refresh-payment" class="primary-button" type="button">Refresh status</button>
<a id="resume-payment" class="primary-button" hidden>Resume payment</a>
<button id="cancel-payment" class="primary-button" hidden>Cancel payment and release stock</button>
<a class="primary-button" href="{{ url('/') }}">Return to KITA</a>
</div></main>
<script>
const uuid={{ Illuminate\Support\Js::from(request('uuid', '')) }};
const csrf={{ Illuminate\Support\Js::from(csrf_token()) }};
const message=document.getElementById('payment-status'),cancel=document.getElementById('cancel-payment');
const resume=document.getElementById('resume-payment');
let refreshTimer, attempts=0;
async function check(){
  clearTimeout(refreshTimer);
  try {
    const r=await fetch('/api/transactions/'+encodeURIComponent(uuid),{headers:{Accept:'application/json'}});
    const data=await r.json();if(!r.ok)throw new Error(data.message||'Sign in to view this transaction.');
    message.textContent='Transaction '+data.uuid+': '+data.status+'.';
    cancel.hidden=data.status!=='Pending Payment';
    resume.hidden=true;
    if(data.status==='Pending Payment' && data.checkoutUrl){
      const target=new URL(data.checkoutUrl);
      if(target.protocol==='https:'){resume.href=target.href;resume.hidden=false;}
    }
    if(data.status==='Pending Payment'){
      message.textContent+=' Stock remains reserved until payment or cancellation is confirmed.';
      if(++attempts<40)refreshTimer=setTimeout(check,3000);
    }
  }catch(e){message.textContent=e.message;}
}
cancel.onclick=async()=>{
  clearTimeout(refreshTimer);
  cancel.disabled=true;
  try{
    const r=await fetch('/api/payments/paymongo/'+encodeURIComponent(uuid)+'/cancel',{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf}});
    const data=await r.json();if(!r.ok)throw new Error(data.message||'Cancellation failed.');
    message.textContent=data.message;cancel.hidden=true;resume.hidden=true;
  }catch(e){message.textContent=e.message;}finally{cancel.disabled=false;}
};
document.getElementById('refresh-payment').onclick=()=>{attempts=0;check();};
check();
</script></body></html>
