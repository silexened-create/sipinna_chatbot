# Darfi AI — Asistente Virtual SIPINNA

**Darfi AI** es un chatbot conversacional interactivo para el Sistema de Protección Integral de Niñas, Niños y Adolescentes (SIPINNA). Utiliza un sistema RAG (Retrieval-Augmented Generation) semántico en PHP/JS y la API de OpenRouter para responder preguntas sobre los derechos y protección de la infancia.

---

## 🚀 Características

- **RAG Semántico:** Búsqueda por intención y palabras clave sobre `data/knowledge.json`.
- **Integración con LLM:** Fallback dinámico con modelos vía OpenRouter (`api.php`).
- **Respuesta de Voz (TTS):** Generación de voz interactiva vía OpenRouter TTS con fallback al motor nativo del navegador (Web Speech API).
- **Módulo de Moderación:** Filtro de contenido no relacionado o inadecuado (`filters.js` / `api.php`).
- **Entrada por Voz:** Soporte para reconocimiento de voz en el navegador.

---

## 🛠️ Estructura del Proyecto

```text
darfi-ai/
├── backend/
│   ├── api.php        # Controlador principal (RAG, moderación y OpenRouter API)
│   └── tts.php        # Endpoint para generación de audio TTS
├── data/
│   └── knowledge.json # Base de conocimiento RAG de SIPINNA
├── moderation/
│   └── filters.js     # Filtros de moderación en frontend
├── rag/
│   └── sipinna_rag.js # Módulo RAG cliente
├── chat.js            # Lógica principal del chat en frontend
├── index.html         # Interfaz web principal
├── styles.css         # Estilos visuales
└── .htaccess          # Configuración del servidor y protección de .env
```

---

## ⚙️ Configuración e Instalación

1. **Clonar el repositorio:**
   ```bash
   git clone https://github.com/tu-usuario/darfi-ai.git
   cd darfi-ai
   ```

2. **Configurar variables de entorno:**
   Copia el archivo de ejemplo y añade tu API key de OpenRouter:
   ```bash
   cp .env.example .env
   ```
   Abre `.env` y define `OPENROUTER_API_KEY`:
   ```env
   OPENROUTER_API_KEY=tu_api_key_de_openrouter
   ```

3. **Requisitos de Servidor:**
   - Servidor PHP 7.4+ o 8.x con extensión `cURL` habilitada.
   - Servidor Web (Apache/Nginx) con soporte `.htaccess` para proteger `.env`.

---

## 🔒 Seguridad
- Nunca subas el archivo `.env` al repositorio público.
- El archivo `.htaccess` incluye reglas para bloquear el acceso web directo a `.env`.
