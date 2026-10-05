// ==============================================
// filters.js — Moderation module for Darfi chatbot
// Checks user input against forbidden categories.
// ==============================================

/**
 * List of banned keywords organized by category.
 * If any of these appear in the user's message,
 * it will be rejected.
 */
const bannedKeywords = {
  // Politics
  politica: [
    "política", "politica", "elecciones", "partido político",
    "candidato", "campaña electoral", "voto", "diputado",
    "senador", "gobernador", "presidente"
  ],
  // Violence (graphic/explicit)
  violencia_explicita: [
    "armas de fuego", "narcotráfico", "sicario",
    "asesinato", "tortura", "descuartizar", "drogas"
  ],
  // Adult content
  contenido_adulto: [
    "pornografía", "pornografia", "sexo explícito",
    "contenido sexual", "desnudos", "xxx"
  ],
  // Unrelated topics
  temas_no_relacionados: [
    "apuestas", "casino", "criptomonedas", "forex",
    "recetas de cocina", "horóscopo", "videojuegos",
    "fútbol", "futbol"
  ]
};

/**
 * Checks whether the given text is allowed.
 * Returns true if the text passes moderation,
 * false if it contains banned content.
 * @param {string} text — the user's raw input
 * @returns {boolean}
 */
function is_allowed(text) {
  if (!text) return false;

  // Normalize: lowercase and remove accents
  var normalized = text
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "");

  // Check every category
  var categories = Object.keys(bannedKeywords);
  for (var i = 0; i < categories.length; i++) {
    var keywords = bannedKeywords[categories[i]];
    for (var j = 0; j < keywords.length; j++) {
      var kw = keywords[j]
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "");
      if (normalized.indexOf(kw) !== -1) {
        console.warn(
          "Mensaje bloqueado por la categoría:",
          categories[i],
          "| Palabra clave:",
          keywords[j]
        );
        return false;
      }
    }
  }

  return true;
}
