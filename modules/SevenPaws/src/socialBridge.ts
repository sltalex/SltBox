import { invoke } from "@tauri-apps/api/core";
import type { CustomCat } from "./types";
export interface PetCare { experience: number; level: number; level_progress: number; level_goal: number; hunger: number; mood: number; last_play_at: number; badge: string; effect: string }
export interface ShopItem { id: string; name: string; price: number; kind: "food" | "toy" | "badge" | "effect"; description: string }
export interface LevelReward { id: string; name: string; level: number; kind: "action" | "badge" | "effect"; description: string }
export interface PetProfile { user_id: string; name: string; username?: string; revision: number; outfit: { coat: string; cats: CustomCat[] }; friend_code?: string; coins?: number; reward_day?: string; care?: PetCare; presence?: "online" | "offline" | "busy" }
export interface PetSession { id: string; kind: "visit" | "focus"; status: "invited" | "active"; sender: string; recipient: string; expires_at: number; other: PetProfile }
export interface PetEvent { id: string; sender: string; recipient: string; sender_name?: string; recipient_name?: string; kind: "gift" | "wave" | "highfive" | "feed" | "play" | "focus_done" | "visit_start" | "focus_start" | "celebrate" | "reward_2" | "reward_3" | "reward_5"; created_at: number }
export interface SocialState { connected: boolean; message: string; me?: PetProfile; account_wallet?: { initialized: boolean; coins: number; currency: "account_coins" }; inventory?: Record<string,number>; shop?: ShopItem[]; rewards?: LevelReward[]; directory?: PetProfile[]; directory_more?: boolean; directory_search?: string; friends?: PetProfile[]; sessions?: PetSession[]; events?: PetEvent[]; server_time?: number; result_id?: string; result_message?: string }
export const getSocial = () => invoke<SocialState>("get_social");
export async function submitSocial(action: string, extra: Record<string, unknown> = {}): Promise<string> {
  const id = crypto.randomUUID().replaceAll("-", "");
  await invoke("submit_social", { command: { action, operation_id: id, ...extra } });
  return id;
}
