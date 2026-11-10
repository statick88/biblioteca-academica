(() => {
  document.documentElement.classList.add('js');
  const toggle = document.querySelector('.nav-toggle');
  const nav = document.querySelector('.sidebar');
  if (toggle && nav) {
    const close = () => {
      nav.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.focus();
    };
    toggle.addEventListener('click', () => {
      const open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && nav.classList.contains('open')) close();
    });
    window.addEventListener('resize', () => {
      if (window.innerWidth >= 768) nav.classList.remove('open');
    });
  }
  document.querySelectorAll('[data-confirm]').forEach((button) => {
    button.addEventListener('click', (event) => {
      if (!window.confirm(button.getAttribute('data-confirm') || '¿Continuar?')) event.preventDefault();
    });
  });
  document.querySelectorAll('form').forEach((form) => form.addEventListener('submit', () => {
    const submit = form.querySelector('button[type="submit"], button:not([type])');
    if (submit) submit.textContent = 'Enviando…';
  }));
})();
