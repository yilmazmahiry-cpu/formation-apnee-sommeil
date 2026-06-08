// Accordion modules
document.querySelectorAll('.module-header').forEach(header => {
  header.addEventListener('click', () => {
    const body = header.nextElementSibling;
    const btn = header.querySelector('.module-toggle');
    const isOpen = body.classList.contains('open');

    // Fermer tous les autres
    document.querySelectorAll('.module-body.open').forEach(b => b.classList.remove('open'));
    document.querySelectorAll('.module-toggle.open').forEach(b => b.classList.remove('open'));

    if (!isOpen) {
      body.classList.add('open');
      btn.classList.add('open');
    }
  });
});

// FAQ accordion
document.querySelectorAll('.faq-question').forEach(btn => {
  btn.addEventListener('click', () => {
    const answer = btn.nextElementSibling;
    const isOpen = answer.classList.contains('open');

    document.querySelectorAll('.faq-answer.open').forEach(a => a.classList.remove('open'));
    document.querySelectorAll('.faq-question.open').forEach(b => b.classList.remove('open'));

    if (!isOpen) {
      answer.classList.add('open');
      btn.classList.add('open');
    }
  });
});

// Navbar scroll shadow
window.addEventListener('scroll', () => {
  const nav = document.querySelector('.navbar');
  if (window.scrollY > 8) {
    nav.style.boxShadow = '0 2px 16px rgba(0,0,0,.08)';
  } else {
    nav.style.boxShadow = 'none';
  }
});
