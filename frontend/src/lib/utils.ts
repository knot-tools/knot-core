/**
 * Knot UI utilities — class-variance-authority + tailwind-merge helper.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs));
}
