// Whether this device can hand a file to another app, such as WhatsApp, through its share menu.
// Phones can; many computers cannot, and no browser can on a page served without HTTPS.
export function canShareFile(file) {
  return (
    typeof navigator.share === 'function' &&
    typeof navigator.canShare === 'function' &&
    navigator.canShare({ files: [file] })
  )
}

// Save a file to the device's downloads folder.
export function downloadFile(file) {
  const url = URL.createObjectURL(file)
  const link = document.createElement('a')

  link.href = url
  link.download = file.name
  document.body.append(link)
  link.click()
  link.remove()

  // Released once the download has had time to start.
  setTimeout(() => URL.revokeObjectURL(url), 10_000)
}
