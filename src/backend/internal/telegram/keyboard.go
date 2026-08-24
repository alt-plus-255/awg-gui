package telegram

func Inline(rows [][]map[string]string) map[string]any {
	return map[string]any{"inline_keyboard": rows}
}

func Btn(text, callbackData string) map[string]string {
	if len([]rune(callbackData)) > 64 {
		callbackData = string([]rune(callbackData)[:64])
	}
	return map[string]string{"text": text, "callback_data": callbackData}
}

func Chunk(buttons []map[string]string, perRow int) [][]map[string]string {
	if perRow < 1 {
		perRow = 1
	}
	var rows [][]map[string]string
	for i := 0; i < len(buttons); i += perRow {
		end := i + perRow
		if end > len(buttons) {
			end = len(buttons)
		}
		rows = append(rows, buttons[i:end])
	}
	return rows
}
