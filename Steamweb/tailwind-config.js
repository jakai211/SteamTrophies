// our Tailwind colors and fonts, shared by every page
tailwind.config = {
  theme: {
    extend: {
      colors: {
        nav: '#171A21',
        page: '#1B2838',
        panel: '#1E2D3D',
        raised: '#2A475E',
        accent: '#66C0F4',
        accentdk: '#417A9B',
        text: '#C7D5E0',
        muted: '#8F98A0',
        success: '#A4D007',
        danger: '#C94A4A',
        platinum: '#B9E6FF',
        gold: '#E5B84B',
        silver: '#C0C6CC',
        bronze: '#C97C46',
        veryrare: '#9B6BDF'
      },
      fontFamily: {
        sans: ['Inter', 'Arial', 'sans-serif'],
        mono: ['Consolas', 'Menlo', 'monospace']
      }
    }
  }
}