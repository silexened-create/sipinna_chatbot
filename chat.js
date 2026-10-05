// ==============================================
// chat.js — Main chat logic for Darfi chatbot
// Handles: message sending, display, voice
// recognition (Web Speech API), text-to-speech,
// markdown formatting, and avatar animation.
// ==============================================

(function () {
  "use strict";

  // ---- Dynamic viewport height fix for mobile browsers ----
  // Sets a CSS custom property --vh that tracks the real visible viewport,
  // so calc(var(--vh) * 100) equals the true inner height even when
  // the on-screen keyboard is open.  This is the fallback for browsers
  // that don't support 100dvh.
  function setAppHeight() {
    var vh = window.innerHeight * 0.01;
    document.documentElement.style.setProperty("--vh", vh + "px");
  }
  setAppHeight();
  window.addEventListener("resize", setAppHeight);
  window.addEventListener("orientationchange", function () {
    // Small delay lets the browser finish the orientation animation
    setTimeout(setAppHeight, 120);
  });
  // Also recalculate when the visual viewport changes (keyboard open/close)
  if (window.visualViewport) {
    window.visualViewport.addEventListener("resize", setAppHeight);
  }

  // ---- DOM references ----
  const chatMessages = document.getElementById("chat-messages");
  const userInput = document.getElementById("user-input");
  const btnSend = document.getElementById("btn-send");
  const btnVoice = document.getElementById("btn-voice");
  const darfiAvatar = document.getElementById("darfi-avatar");

  // ---- State ----
  let isListening = false;
  let recognition = null;

  // =========================================
  // A. Convert markdown (**bold**) to HTML
  // =========================================
  function renderMarkdown(text) {
    if (!text) return "";
    // Convert **bold** → <strong>bold</strong>
    return text.replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>");
  }

  // =========================================
  // B. Clean text for TTS (remove emojis, symbols, **bold**)
  // =========================================
  function cleanForTTS(text) {
    if (!text) return "";

    let clean = text;

    // Remove emojis (unicode ranges)
    clean = clean.replace(/[\u{1F300}-\u{1FAFF}]/gu, "");

    // Remove misc symbols (stars, warning signs, etc.)
    clean = clean.replace(/[\u2600-\u26FF]/g, "");

    // Remove **bold markers** but keep the text
    clean = clean.replace(/\*\*(.*?)\*\*/g, "$1");

    // Remove any leftover non‑text symbols
    clean = clean.replace(/[^\w\sáéíóúñÁÉÍÓÚÑ.,!?¿¡]/g, "");

    // Normalize spaces
    clean = clean.replace(/\s+/g, " ").trim();

    return clean;
  }

  // =========================================
  // C. Display a message in the chat window
  // =========================================
  function displayMessage(text, sender) {
    const msgDiv = document.createElement("div");
    msgDiv.classList.add("message", sender);

    // Render markdown safely
    msgDiv.innerHTML = renderMarkdown(text);

    chatMessages.appendChild(msgDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
  }

  // =========================================
  // D. Send user message to the backend
  // =========================================
  async function sendMessage(text) {
    if (!text || text.trim() === "") return;

    displayMessage(text, "user");
    userInput.value = "";

    try {
      // 1. Fetch text response instantly
      console.log(`\n[${new Date().toLocaleTimeString()}] 🚀 Enviando mensaje a api.php...`);
      const startApi = performance.now();
      
      const response = await fetch("backend/api.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ mensaje: text }),
      });

      const data = await response.json();
      const endApi = performance.now();
      console.log(`[${new Date().toLocaleTimeString()}] ✅ Respuesta de texto (api.php) recibida en ${((endApi - startApi) / 1000).toFixed(2)} segundos.`);
      if (data._model_used) {
        console.log(`[API] Final model used: ${data._model_used}`);
      }

      displayMessage(data.respuesta, "darfi");
      
      const fallbackText = cleanForTTS(data.tts);

      // 2. Fetch audio asynchronously from the new TTS endpoint
      darfiAvatar.classList.add("speaking");
      
      try {
          console.log(`[${new Date().toLocaleTimeString()}] 🎵 Solicitando audio a tts.php... (Longitud texto original: ${fallbackText.length} caracteres)`);
          const startTts = performance.now();
          
          const audioResponse = await fetch("backend/tts.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ text: fallbackText }),
          });
          
          const audioData = await audioResponse.json();
          const endTts = performance.now();
          console.log(`[${new Date().toLocaleTimeString()}] 🔊 Respuesta de audio (tts.php) recibida en ${((endTts - startTts) / 1000).toFixed(2)} segundos.`);

          if (audioData.audio) {
              playDarfiAudio(audioData.audio, fallbackText);
          } else {
              console.warn("No audio returned from tts.php, falling back.");
              speakWithFallback(fallbackText);
          }
      } catch (err) {
          console.error(`[${new Date().toLocaleTimeString()}] ❌ Error fetching TTS:`, err);
          speakWithFallback(fallbackText);
      }

    } catch (error) {
      console.error(`[${new Date().toLocaleTimeString()}] ❌ Error al comunicarse con el servidor:`, error);
      displayMessage(
        "Lo siento, hubo un error al procesar tu mensaje. Intenta de nuevo.",
        "darfi"
      );
    }
  }

  // =========================================
  // E. Text-to-Speech (Azure TTS + Fallback)
  // =========================================

  // Currently playing audio element (to allow cancellation)
  let currentAudio = null;

  /**
   * Play Darfi's voice response.
   * Primary: OpenRouter TTS base64 MP3 audio.
   * Fallback: Browser SpeechSynthesis with optimized voice.
   */
  function playDarfiAudio(audioBase64, fallbackText) {
    // Stop any currently playing audio
    stopCurrentAudio();

    if (audioBase64) {
      // ---- Primary: Play OpenRouter TTS MP3 ----
      console.log("🎵 Attempting to initialize and play OpenRouter base64 MP3 audio...");
      try {
        const audio = new Audio();
        audio.src = audioBase64;
        currentAudio = audio;

        audio.onplay = function () {
          darfiAvatar.classList.add("speaking");
        };

        audio.onended = function () {
          darfiAvatar.classList.remove("speaking");
          currentAudio = null;
        };

        audio.onerror = function (event) {
          console.error("Error al reproducir audio OpenRouter, usando fallback. Detalles del error:", event);
          darfiAvatar.classList.remove("speaking");
          currentAudio = null;
          speakWithFallback(fallbackText);
        };

        audio.play().catch(function (err) {
          console.error("No se pudo reproducir audio:", err);
          speakWithFallback(fallbackText);
        });
      } catch (e) {
        console.error("Error creando Audio:", e);
        speakWithFallback(fallbackText);
      }
    } else {
      // ---- No OpenRouter audio available ----
      console.warn("⚠️ No audio string provided by backend, diverting to browser SpeechSynthesis fallback.");
      speakWithFallback(fallbackText);
    }
  }

  /**
   * Stop any currently playing audio or speech.
   */
  function stopCurrentAudio() {
    if (currentAudio) {
      currentAudio.pause();
      currentAudio = null;
    }
    if (window.speechSynthesis) {
      window.speechSynthesis.cancel();
    }
    darfiAvatar.classList.remove("speaking");
  }

  /**
   * Fallback: Improved browser SpeechSynthesis.
   * Selects the best available Spanish voice
   * and uses warm prosody settings.
   */
  function speakWithFallback(text) {
    if (!text || !window.speechSynthesis) return;

    window.speechSynthesis.cancel();

    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = "es-MX";
    utterance.rate = 0.9;    // Slightly slower — easier for kids
    utterance.pitch = 1.15;  // Slightly higher — warmer, friendlier
    utterance.volume = 0.85; // Soft, gentle volume

    // Try to pick the best Spanish voice available
    const voices = window.speechSynthesis.getVoices();
    const bestVoice = pickBestSpanishVoice(voices);
    if (bestVoice) {
      utterance.voice = bestVoice;
    }

    utterance.onstart = function () {
      darfiAvatar.classList.add("speaking");
    };

    utterance.onend = function () {
      darfiAvatar.classList.remove("speaking");
    };

    window.speechSynthesis.speak(utterance);
  }

  /**
   * Pick the best Spanish voice from available voices.
   * Priority: Microsoft/Google neural es-MX > any es-MX > any es-*
   */
  function pickBestSpanishVoice(voices) {
    if (!voices || voices.length === 0) return null;

    // Priority tiers
    var esMxNeural = null;
    var esMxAny = null;
    var esAny = null;

    for (var i = 0; i < voices.length; i++) {
      var v = voices[i];
      var lang = (v.lang || "").toLowerCase();
      var name = (v.name || "").toLowerCase();

      if (lang.indexOf("es-mx") !== -1 || lang.indexOf("es_mx") !== -1) {
        if (name.indexOf("microsoft") !== -1 || name.indexOf("google") !== -1) {
          if (!esMxNeural) esMxNeural = v;
        }
        if (!esMxAny) esMxAny = v;
      } else if (lang.indexOf("es") !== -1) {
        if (!esAny) esAny = v;
      }
    }

    return esMxNeural || esMxAny || esAny || null;
  }

  // =========================================
  // G. Voice Recognition (Web Speech API)
  // =========================================
  function initVoiceRecognition() {
    const SpeechRecognition =
      window.SpeechRecognition || window.webkitSpeechRecognition;

    if (!SpeechRecognition) {
      btnVoice.disabled = true;
      btnVoice.textContent = "🎤 No disponible";
      console.warn("El reconocimiento de voz no está soportado en este navegador.");
      return;
    }

    recognition = new SpeechRecognition();
    recognition.lang = "es-MX";
    recognition.interimResults = false;
    recognition.maxAlternatives = 1;

    recognition.onresult = function (event) {
      const transcript = event.results[0][0].transcript;
      userInput.value = transcript;
      sendMessage(transcript);
    };

    recognition.onend = function () {
      isListening = false;
      btnVoice.classList.remove("listening");
      btnVoice.textContent = "🎤 Hablar";
    };

    recognition.onerror = function (event) {
      console.error("Error de reconocimiento de voz:", event.error);
      isListening = false;
      btnVoice.classList.remove("listening");
      btnVoice.textContent = "🎤 Hablar";
    };
  }

  function toggleVoiceRecognition() {
    if (!recognition) return;

    if (isListening) {
      recognition.stop();
      isListening = false;
      btnVoice.classList.remove("listening");
      btnVoice.textContent = "🎤 Hablar";
    } else {
      recognition.start();
      isListening = true;
      btnVoice.classList.add("listening");
      btnVoice.textContent = "⏹ Escuchando...";
    }
  }

  // =========================================
  // H. Event listeners
  // =========================================
  btnSend.addEventListener("click", function () {
    sendMessage(userInput.value);
  });

  userInput.addEventListener("keydown", function (e) {
    if (e.key === "Enter") {
      sendMessage(userInput.value);
    }
  });

  btnVoice.addEventListener("click", function () {
    toggleVoiceRecognition();
  });

  // =========================================
  // I. Initialization
  // =========================================
  initVoiceRecognition();

  displayMessage(
    "Puedes preguntarme sobre los derechos de niñas, niños y adolescentes, o sobre el trabajo que hace SIPINNA. ¿Qué te gustaría saber?",
    "darfi"
  );
})();
