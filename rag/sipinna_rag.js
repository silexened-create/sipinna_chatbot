// ==============================================
// sipinna_rag.js — RAG semántico estilo IAstronaut
// Con logs completos para depuración
// ==============================================

let knowledgeData = null;

// A. Load knowledge.json
async function loadKnowledge() {
  try {
    const response = await fetch("data/knowledge.json");
    if (!response.ok) throw new Error("Error: " + response.status);
    knowledgeData = await response.json();
    console.log("📚 RAG — Base de conocimiento cargada:", knowledgeData.documents.length, "documentos");
  } catch (error) {
    console.error("❌ RAG — Error al cargar knowledge.json:", error);
    knowledgeData = { documents: [] };
  }
}

// B. Deep normalization
function normalize(text) {
  return text
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^a-z0-9\s]/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

// C. Semantic keyword expansion (synonyms)
const SEMANTIC_EXPANSIONS = {
  sipinna: ["sipinna", "sistema de proteccion", "proteccion integral", "derechos de ninos"],
  violencia: ["violencia", "maltrato", "abuso", "acoso", "bullying", "riesgos", "ciberacoso"],
  participacion: ["participacion", "opinion", "voz", "expresion", "consulta infantil"],
  crianza: ["crianza", "crianza positiva", "familia", "padres", "hijos", "comunicacion"],
  servicios: ["servicios", "ayuda", "apoyo", "orientacion", "acompanamiento"],
  liderazgo: ["titular", "directora", "encargada", "responsable", "coordinadora", "liderazgo", "lider", "laura monica marin", "promupinna"],
  ejes: ["ejes", "lineas de accion", "acciones", "programas", "trabajo sipinna"]
};

// D. INTENT MAP semántico
const INTENT_MAP = [
  { id: "que_es_sipinna", docId: "que_es_sipinna", keys: ["sipinna", "funcion", "que es", "para que sirve"] },
  { id: "ejes_accion", docId: "ejes_accion", keys: ["ejes", "acciones", "programas", "lineas de accion"] },
  { id: "coordinacion_interinstitucional", docId: "coordinacion_interinstitucional", keys: ["coordinacion", "instituciones", "dif", "organizaciones"] },
  { id: "prevencion_proteccion", docId: "prevencion_proteccion", keys: ["violencia", "riesgos", "proteccion", "prevencion"] },
  { id: "participacion_infantil", docId: "participacion_infantil", keys: ["participacion", "opinion", "consulta infantil"] },
  { id: "crianza_positiva", docId: "crianza_positiva", keys: ["crianza", "familia", "padres", "hijos"] },
  { id: "liderazgo_sipinna", docId: "liderazgo_sipinna", keys: ["titular", "directora", "lider", "laura monica marin"] },
  { id: "derechos_generales", docId: "derechos_generales", keys: ["derechos", "proteccion integral", "interes superior"] },
  { id: "prevencion_violencia", docId: "prevencion_violencia", keys: ["violencia", "maltrato", "abuso", "acoso"] },
  { id: "participacion", docId: "participacion", keys: ["participacion", "opinion", "voz"] },
  { id: "servicios_sipinna", docId: "servicios_sipinna", keys: ["servicios", "ayuda", "apoyo", "orientacion"] },
  { id: "faq", docId: "faq", keys: ["pregunta", "faq", "informacion general"] }
];

// E. Fuzzy matching (semantic scoring)
function fuzzyScore(query, keyword) {
  const q = normalize(query);
  const k = normalize(keyword);

  if (q.includes(k)) return 3; // exact match
  if (q.startsWith(k)) return 2;
  if (q.endsWith(k)) return 2;

  // partial match
  if (q.split(" ").some(word => k.includes(word) && word.length > 3)) return 1;

  return 0;
}

// F. Semantic scoring
function scoreIntent(query, intent) {
  let score = 0;

  for (const key of intent.keys) {
    score += fuzzyScore(query, key);

    // semantic expansions
    if (SEMANTIC_EXPANSIONS[key]) {
      for (const syn of SEMANTIC_EXPANSIONS[key]) {
        score += fuzzyScore(query, syn);
      }
    }
  }

  return score;
}

// G. Main RAG function
function buscarContextoSipinna(query) {
  if (!knowledgeData || !knowledgeData.documents) {
    console.warn("⚠️ RAG — knowledgeData vacío");
    return "";
  }

  const q = normalize(query);
  console.log("🔍 RAG — Consulta original:", query);
  console.log("🔍 RAG — Consulta normalizada:", q);

  const scored = INTENT_MAP.map(intent => ({
    intent,
    score: scoreIntent(q, intent)
  }))
    .filter(s => s.score > 0)
    .sort((a, b) => b.score - a.score)
    .slice(0, 3);

  console.log("📊 RAG — Intents detectados:", scored);

  if (scored.length === 0) {
    console.warn("⚠️ RAG — No se detectó ningún intent");
    return "";
  }

  const blocks = scored
    .map(s => {
      const doc = knowledgeData.documents.find(d => d.id === s.intent.docId);

      if (doc) {
        console.log(`📄 RAG — Documento usado: ${doc.id} (${doc.title})`);
        return `${doc.title}: ${doc.text}`;
      } else {
        console.warn(`⚠️ RAG — Documento NO encontrado: ${s.intent.docId}`);
        return "";
      }
    })
    .filter(Boolean);

  const finalContext = blocks.join("\n\n");

  console.log("📚 RAG — Contexto final enviado al modelo:", finalContext);

  return finalContext;
}

// H. Auto-load
loadKnowledge();
