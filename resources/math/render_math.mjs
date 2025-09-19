import { readFileSync } from 'node:fs';

// eslint-disable-next-line no-undef
const args = process.argv.slice(2);
const engineArg = args.find(a => a.startsWith('--engine='));
// eslint-disable-next-line @typescript-eslint/no-unused-vars
const engine = engineArg ? engineArg.split('=')[1] : 'mathjax';

const input = JSON.parse(readFileSync(0, 'utf8'));
const exprs = input.exprs || [];

async function renderMathJax(items) {
    const { mathjax } = await import('mathjax-full/js/mathjax.js');
    const { TeX } = await import('mathjax-full/js/input/tex.js');
    const { SVG } = await import('mathjax-full/js/output/svg.js');
    const { liteAdaptor } = await import('mathjax-full/js/adaptors/liteAdaptor.js');
    const { RegisterHTMLHandler } = await import('mathjax-full/js/handlers/html.js');

    const adaptor = liteAdaptor();
    RegisterHTMLHandler(adaptor);

    const tex = new TeX({packages: ['base', 'ams', 'newcommand', 'configMacros']});
    const svg = new SVG({fontCache: 'local'});
    const doc = mathjax.document('', {InputJax: tex, OutputJax: svg});

    const results = {};

    for (const item of items) {
        try {
            const node = doc.convert(item.tex, {display: item.display});
            results[item.id] = adaptor.outerHTML(node);
        } catch (err) {
            console.error(`Error rendering ${item.id}:`, err.message);
            results[item.id] = item.tex;
        }
    }

    return results;
}

const results = await renderMathJax(exprs);
console.log(JSON.stringify({ results }));
