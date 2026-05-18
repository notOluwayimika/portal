import { format } from 'date-fns'

export const isEmpty = (value: any) => {
  if (Array.isArray(value)) return !value.length

  if (value === undefined || value === null) {
    return true
  }

  if (value === false) {
    return true
  }

  if (value instanceof Date) {
    return isNaN(value.getTime())
  }

  if (typeof value === 'object') {
    for (const _ in value) return false

    return true
  }

  return !String(value).length
}

export const ExcelDateToJSDate = (date: number) => new Date(Math.round((date - 25569) * 86400 * 1000))

export const formatDate = (
  date: string | Date | null | undefined,
  formatStr = 'do MMMM, yyyy',
): string => {
  if (!date) return ''

  const parsed = new Date(date)
  if (isNaN(parsed.getTime())) return ''

  return format(parsed, formatStr)
}

/**
 * Capitalizes the first character of each word in a string
 */
export const ucWords = (word: string) => {
  if (!isEmpty(word)) {
    return word.replace(/\b[a-z]/g, function (text) {
      return text.toUpperCase()
    })
  }
  return word
}

/**
 * Capitalizes only the first character of the entire string
 */
export const ucFirst = (word: string): string => {
  if (!isEmpty(word)) {
    return word.charAt(0).toUpperCase() + word.slice(1)
  }
  return word
}

export const snakeToTitleCase = (input?: string): string => {
  if (!input) return ''
  return ucWords(String(input).toLowerCase().replace(/_/g, ' '))
}

/**
 * Scroll to a particular element position defined
 *
 * *Example usage*
 *
 ** `const modal = document.querySelector('.your-modal-selector');`
 ** `const formText = document.querySelector('span.form-text');`
 ** `const formGroup = formText.closest('.form-group');`
 ** `scrollToPosition(modal, formGroup);`
 */
export const scrollToPosition = (container: HTMLElement, scrollTo: HTMLElement): void => {
  container.scrollTo({
    top:
      scrollTo.getBoundingClientRect().top -
      container.getBoundingClientRect().top +
      container.scrollTop,
    behavior: 'smooth',
  })
}

export const scrollTo = (boxId: string): void => {
  const box = document.getElementById(boxId)
  if (box) {
    box.scrollIntoView({ behavior: 'smooth' })
  }
}

/**
 * Group Laravel validation errors by row for dynamic multi-row forms
 */
export const groupedLaravelErrors = (
  errors?: Record<string, string[]>
): Record<string, string[]> => {
  const groupedErrors: Record<string, string[]> = {};

  if (!errors) return groupedErrors;

  for (const key in errors) {
    if (Object.prototype.hasOwnProperty.call(errors, key)) {
      const errorArray = errors[key];
      if (!errorArray?.length) continue;

      // Extract row number from the key
      const rowNumber = key.split('.')[0];
      if (!rowNumber) continue;

      // Remove the prefix number and dot from the error message
      const firstError = errorArray[0];
      if (!firstError) continue;

      const errorMessage = firstError.replace(/\d+\./g, '');

      // Group errors by row
      if (!groupedErrors[rowNumber]) {
        groupedErrors[rowNumber] = [];
      }

      groupedErrors[rowNumber].push(errorMessage);
    }
  }

  return groupedErrors;
}

type LaravelErrors = Record<string, string[]>;

/**
 * Format grouped Laravel errors into clean user-friendly messages
 */
export const formatGroupedErrors = (
  errors: LaravelErrors
): Record<string, string[]> => {
  const formatted: Record<string, string[]> = {};

  Object.entries(errors).forEach(([group, messages]) => {
    formatted[group] = messages.map((message) => {
      const cleaned = message
        .replace(/^The\s+/i, "")
        .replace(/\s+field/gi, "")
        .replace(new RegExp(`${group}\\.`, "gi"), "")
        .replace(/when\s+\w+\s+is\s+present\.?/gi, "") // remove condition
        .replace(/\./g, " ")
        .replace(/_/g, " ")
        .replace(/\s+/g, " ") // normalize spaces
        .trim();

      return cleaned.charAt(0).toUpperCase() + cleaned.slice(1);
    });
  });

  return formatted;
};

export const truncateWords = (text: string, limit: number = 20) => {
  if (!text) return ''
  const words = text.split(/\s+/)
  if (words.length <= limit) return text
  return words.slice(0, limit).join(' ') + '...'
}