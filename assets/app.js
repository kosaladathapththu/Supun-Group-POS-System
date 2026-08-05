const simpleStyle=document.createElement('link');simpleStyle.rel='stylesheet';simpleStyle.href='assets/simple.css';document.head.appendChild(simpleStyle);
const numberStyle=document.createElement('style');numberStyle.textContent='input[type="number"]{-moz-appearance:textfield;appearance:textfield}input[type="number"]::-webkit-inner-spin-button,input[type="number"]::-webkit-outer-spin-button{-webkit-appearance:none;margin:0}';document.head.appendChild(numberStyle);
document.querySelector('[data-menu]')?.addEventListener('click',()=>document.querySelector('.sidebar')?.classList.toggle('open'));
document.querySelectorAll('[data-confirm]').forEach(el=>el.addEventListener('click',e=>{if(!confirm(el.dataset.confirm))e.preventDefault()}));
