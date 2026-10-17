/*
 * Movimiento de la interfaz (estilo sitios Wix):
 *  - los bloques de cada pantalla aparecen al desplazarse,
 *  - las cifras marcadas con data-contar cuentan desde cero,
 *  - el encabezado toma sombra al bajar.
 * Si algo falla, todo queda visible igual (el HTML ya trae los valores finales).
 */

const suave = (t) => 1 - Math.pow(1 - t, 4);

/** Cuenta una cifra desde cero hasta su valor (data-contar="1234.5", data-decimales, data-prefijo, data-sufijo). */
function contar(el) {
    if (el.dataset.contado) return;
    el.dataset.contado = '1';
    const fin = parseFloat(el.dataset.contar);
    if (!isFinite(fin) || fin === 0) return;
    const decimales = parseInt(el.dataset.decimales ?? '0', 10);
    const formato = new Intl.NumberFormat('en-US', {minimumFractionDigits: decimales, maximumFractionDigits: decimales});
    const antes = el.dataset.prefijo ?? '';
    const despues = el.dataset.sufijo ?? '';
    const duracion = Math.min(1600, 700 + Math.log10(Math.abs(fin) + 1) * 250);
    const inicio = performance.now();
    const paso = (ahora) => {
        const t = Math.min(1, (ahora - inicio) / duracion);
        el.textContent = antes + formato.format(fin * suave(t)) + despues;
        if (t < 1) requestAnimationFrame(paso);
    };
    requestAnimationFrame(paso);
}

const observador = 'IntersectionObserver' in window
    ? new IntersectionObserver((entradas) => {
        entradas.forEach((e) => {
            if (!e.isIntersecting) return;
            e.target.classList.add('visible');
            e.target.querySelectorAll?.('[data-contar]').forEach(contar);
            if (e.target.matches?.('[data-contar]')) contar(e.target);
            observador.unobserve(e.target);
        });
    }, {rootMargin: '0px 0px -6% 0px', threshold: 0})
    : null;

/** Prepara las animaciones dentro de un bloque (se usa también con el HTML que llega por fetch). */
function animar(raiz = document) {
    const bloques = [...raiz.querySelectorAll('.contenido > :not(.banda), .revelar')];
    bloques.forEach((b, i) => {
        if (b.classList.contains('visible')) return;
        b.style.setProperty('--retraso', `${Math.min(i, 6) * 70}ms`);
        observador ? observador.observe(b) : b.classList.add('visible');
    });
    raiz.querySelectorAll('.banda [data-contar], [data-contar-ya]').forEach(contar);
    raiz.querySelectorAll('[data-contar]').forEach((c) => (observador && !c.closest('.revelar, .contenido > *') ? observador.observe(c) : null));
}

window.tmAnimar = animar;
window.tmContar = (raiz) => raiz.querySelectorAll('[data-contar]').forEach(contar);

animar();

const barra = document.querySelector('.barra');
if (barra) {
    const sombra = () => barra.classList.toggle('con-sombra', window.scrollY > 8);
    sombra();
    window.addEventListener('scroll', sombra, {passive: true});
}
