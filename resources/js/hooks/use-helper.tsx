export const ExcelDateToJSDate = (date: number) => new Date(Math.round((date - 25569) * 86400 * 1000))
