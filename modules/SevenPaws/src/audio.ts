class CatAudio {
  private ctx: AudioContext | null = null;
  private enabled = true;
  private graphReady = false;
  private master: GainNode | null = null;
  private buffer: AudioBuffer | null = null;
  private source: AudioBufferSourceNode | null = null;
  private loading: Promise<void> | null = null;
  private current = 0;

  setEnabled(enabled: boolean): void {
    this.enabled = enabled;
    if (!enabled) this.setPurrIntensity(0);
  }

  private ensureGraph(): boolean {
    if (!this.enabled) return false;
    if (!this.ctx) {
      const AC =
        window.AudioContext ||
        (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
      if (!AC) return false;
      this.ctx = new AC();
    }
    if (this.ctx.state === "suspended") {
      void this.ctx.resume().catch(() => {});
    }
    if (this.graphReady) {
      if (!this.buffer && !this.loading) this.loading = this.loadBuffer();
      return true;
    }

    const master = this.ctx.createGain();
    master.gain.value = 0;
    master.connect(this.ctx.destination);
    this.master = master;
    this.graphReady = true;
    this.loading = this.loadBuffer();
    return true;
  }

  private async loadBuffer(): Promise<void> {
    const ctx = this.ctx;
    if (!ctx) return;
    try {
      const response = await fetch("/audio/purring-cat.mp3");
      if (!response.ok) throw new Error("purring audio is unavailable");
      this.buffer = await ctx.decodeAudioData(await response.arrayBuffer());
      if (this.current > 0.01) this.startSource();
    } catch {
      this.buffer = null;
    } finally {
      this.loading = null;
    }
  }

  private startSource(): void {
    if (this.source || !this.ctx || !this.master || !this.buffer) return;
    const source = this.ctx.createBufferSource();
    source.buffer = this.buffer;
    source.loop = true;
    source.connect(this.master);
    source.start();
    this.source = source;
  }

  setPurrIntensity(intensity: number): void {
    const target = this.enabled ? Math.max(0, Math.min(1, intensity)) : 0;
    if (target <= 0.01 && !this.graphReady) return;
    if (Math.abs(target - this.current) < 0.005) return;
    this.current = target;

    if (target > 0.01 && !this.ensureGraph()) return;
    if (!this.ctx || !this.master) return;
    if (target > 0.01 && this.ctx.state === "suspended") {
      void this.ctx.resume().catch(() => {});
    }
    if (target > 0.01) this.startSource();

    const now = this.ctx.currentTime;
    this.master.gain.setTargetAtTime(target * 0.55, now, target > 0.01 ? 0.14 : 0.22);
  }
}

export const catAudio = new CatAudio();
