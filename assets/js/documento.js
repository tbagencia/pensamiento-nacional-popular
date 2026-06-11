/**
 * Document page (/documento/{id}).
 * Wires the shared share menu (share.js) to the single card, using the
 * document payload the server inlines in #doc-data.
 */

const doc = JSON.parse(document.getElementById("doc-data").textContent);

initShare(document.querySelector(".doc-page"), () => doc);
