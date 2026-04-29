/**
 * Swiper Slim Entry — tree-shakeable import of only the modules TVPG uses.
 *
 * Why: The full swiper-bundle.min.js is 154KB. This entry produces a ~80KB
 * IIFE that includes only Core + Navigation + Thumbs + FreeMode + Keyboard.
 *
 * @package TVPG
 * @since   1.5.0
 */
import Swiper from 'swiper';
import { Navigation, Thumbs, FreeMode, Keyboard } from 'swiper/modules';

// CSS imports — processed by PostCSS and extracted to swiper-slim.min.css.
import 'swiper/css';
import 'swiper/css/navigation';
import 'swiper/css/thumbs';
import 'swiper/css/free-mode';

Swiper.use([Navigation, Thumbs, FreeMode, Keyboard]);

window.Swiper = Swiper;
