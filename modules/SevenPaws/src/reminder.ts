export class ReminderController {
  private timer: number | null = null;

  configure(enabled: boolean, text: string, intervalMinutes: number): void {
    this.stop();
    if (!enabled) return;
    const minutes = Number.isFinite(intervalMinutes) ? Math.max(1, Math.floor(intervalMinutes)) : 1;
    const trimmed = text.trim();
    const intervalMs = minutes * 60_000;
    const schedule = () => {
      this.timer = window.setTimeout(() => {
        try {
          this.speak(trimmed);
        } finally {
          schedule();
        }
      }, intervalMs);
    };
    schedule();
  }

  stop(): void {
    if (this.timer !== null) {
      window.clearTimeout(this.timer);
      this.timer = null;
    }
  }

  private speak(text: string): void {
    if (!text) return;
    const synth = window.speechSynthesis;
    if (!synth) return;
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = "zh-CN";
    const voice = this.localVoice(synth);
    if (voice) utterance.voice = voice;
    synth.cancel();
    synth.speak(utterance);
  }

  private localVoice(synth: SpeechSynthesis): SpeechSynthesisVoice | null {
    // Prefer installed local voices so reminder text never reaches an online TTS service.
    const voices = synth.getVoices().filter((voice) => voice.localService);
    return voices.find((voice) => voice.lang.toLowerCase().startsWith("zh-cn"))
      ?? voices.find((voice) => voice.lang.toLowerCase().startsWith("zh"))
      ?? voices[0]
      ?? null;
  }
}
