// The transport supplies server identity and monotonic sequence numbers.
export function mergePresenceDelta(players, payload) {
  const player = payload?.player, id = player?.user_id || player?.account_id;
  if (!id || !Number.isFinite(Number(player.tile_x)) || !Number.isFinite(Number(player.tile_y))) return false;
  const previous = players.get(id), sequence = Number(payload.sequence || 0);
  if (sequence && sequence <= Number(previous?._realtimeSequence || 0)) return false;
  players.set(id, { ...player, _realtimeSequence: sequence });
  return true;
}

export function expirePresence(players, now = Date.now()) {
  let changed = false;
  for (const [id, player] of players) {
    const expires = Date.parse(player.expires_at);
    if (Number.isFinite(expires) && expires <= now) { players.delete(id); changed = true; }
  }
  return changed;
}

export function presencePosition(player) {
  return {
    x: Number.isFinite(player.pixel_x) ? player.pixel_x : (Number(player.tile_x) + .5) * 16,
    y: Number.isFinite(player.pixel_y) ? player.pixel_y : (Number(player.tile_y) + 1) * 16
  };
}

// NPC events use the same monotonic sequence contract as player presence. The
// server remains authoritative for coordinates and routes; this helper only
// merges an accepted event into the render snapshot.
export function mergeNpcDelta(npcs, payload) {
  const id = payload?.npc_id || payload?.id;
  if (!id || !Number.isFinite(Number(payload.tile_x)) || !Number.isFinite(Number(payload.tile_y))) return false;
  const previous = npcs.get(id), sequence = Number(payload.sequence || 0);
  if (sequence && sequence <= Number(previous?._realtimeSequence || 0)) return false;
  const npc = previous || { npc_id: id, name: '村民', enabled: true, runtime: {} };
  npc._realtimeSequence = sequence;
  npc.runtime = { ...(npc.runtime || {}), ...payload };
  delete npc.runtime.type;
  delete npc.runtime.npc_id;
  delete npc.runtime.session_epoch;
  npcs.set(id, npc);
  return true;
}
