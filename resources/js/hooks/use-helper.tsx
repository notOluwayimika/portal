import { format } from 'date-fns'

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