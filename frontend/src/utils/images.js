// Photos are made smaller in the browser before they are uploaded: at most MAX_SIDE pixels on
// the longest side, saved as WebP (or JPEG where the browser cannot make WebP). A 4 MB phone
// photo usually ends up at a few hundred KB, so it uploads quickly over mobile data. Drawing the
// picture again also leaves out what the camera hid in it, such as the place it was taken.
// The server shrinks anything that still arrives large, so this only has to be good, not sure.

export const MAX_SIDE = 1600
const QUALITY = 0.8

// The size that fits within maxSide on both sides, keeping the photo's shape.
export function fitWithin(width, height, maxSide = MAX_SIDE) {
  const scale = Math.min(1, maxSide / Math.max(width, height))

  return {
    width: Math.max(1, Math.round(width * scale)),
    height: Math.max(1, Math.round(height * scale)),
  }
}

// An <img> shows a phone photo the right way up, and so does drawing it on a canvas.
function loadImage(file) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file)
    const image = new Image()

    image.onload = () => {
      URL.revokeObjectURL(url)
      resolve(image)
    }
    image.onerror = () => {
      URL.revokeObjectURL(url)
      reject(new Error('This photo cannot be opened.'))
    }
    image.src = url
  })
}

function draw(image, { width, height }, background) {
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height

  const context = canvas.getContext('2d')

  if (background) {
    context.fillStyle = background
    context.fillRect(0, 0, width, height)
  }

  context.drawImage(image, 0, 0, width, height)

  return canvas
}

function toBlob(canvas, type) {
  return new Promise((resolve) => canvas.toBlob(resolve, type, QUALITY))
}

// The photo made smaller, as a new file. The original comes back when making it smaller would
// not help, or when this browser cannot do it.
export async function compressImage(file) {
  try {
    const image = await loadImage(file)
    const size = fitWithin(image.naturalWidth, image.naturalHeight)

    let blob = await toBlob(draw(image, size), 'image/webp')

    // A browser that cannot make WebP hands back a PNG instead. Use JPEG there, on white,
    // since JPEG has no see-through parts.
    if (blob?.type !== 'image/webp') {
      blob = await toBlob(draw(image, size, '#fff'), 'image/jpeg')
    }

    const resized = size.width < image.naturalWidth

    if (!blob || (!resized && blob.size >= file.size)) {
      return file
    }

    const extension = blob.type === 'image/webp' ? 'webp' : 'jpg'
    const name = `${file.name.replace(/\.[^.]*$/, '') || 'photo'}.${extension}`

    return new File([blob], name, { type: blob.type, lastModified: Date.now() })
  } catch {
    return file
  }
}
