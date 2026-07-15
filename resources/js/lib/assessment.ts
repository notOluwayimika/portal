import type { BehavioralGrade } from '@/types/models';

// Behavioral assessment pillars (boarding parent / form teacher fallback).
export const PILLARS = [
    'punctuality',
    'mental_alertness',
    'respect',
    'neatness',
    'politeness',
    'honesty',
    'relationship_with_peers',
    'teamwork',
    'perseverance',
] as const;

export type Pillar = (typeof PILLARS)[number];

// Psychomotor skill categories — only for categorical-grading curricula.
export const PSYCHOMOTOR_CATEGORIES = [
    'drawing_colouring',
    'cutting_pasting',
    'puzzles_building',
    'climbing_sliding',
] as const;

export type PsychomotorCategory = (typeof PSYCHOMOTOR_CATEGORIES)[number];

// snakeToTitleCase would render "Drawing Colouring", so the slashed labels
// need to be explicit.
export const PSYCHOMOTOR_LABELS: Record<PsychomotorCategory, string> = {
    drawing_colouring: 'Drawing / Colouring',
    cutting_pasting: 'Cutting / Pasting',
    puzzles_building: 'Puzzles / Building',
    climbing_sliding: 'Climbing / Sliding',
};

export const GRADES: BehavioralGrade[] = ['A', 'B', 'C', 'D', 'E'];

export const GRADE_MAPPING: Record<BehavioralGrade, string> = {
    A: 'Excellent',
    B: 'Very Good',
    C: 'Good',
    D: 'Below Average',
    E: 'Poor',
};
