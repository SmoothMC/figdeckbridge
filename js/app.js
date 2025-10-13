document.addEventListener("DOMContentLoaded", async () => {
  const container = document.getElementById("app-content");
  container.innerHTML = `<p>🔄 Lade Figma- und Deck-Daten …</p>`;

  try {
    const [figmaRes, deckRes] = await Promise.all([
      fetch(OC.generateUrl("/apps/figdeckbridge/api/figma/files")),
      fetch(OC.generateUrl("/apps/figdeckbridge/api/deck/boards"))
    ]);
    const figma = await figmaRes.json();
    const decks = await deckRes.json();

    container.innerHTML = `
      <h3>Figma Projekte</h3>
      <pre>${JSON.stringify(figma, null, 2)}</pre>
      <h3>Deck Boards</h3>
      <pre>${JSON.stringify(decks, null, 2)}</pre>
    `;
  } catch (err) {
    container.innerHTML = `<p style="color:red;">❌ Fehler beim Laden: ${err.message}</p>`;
  }
});
