/**
 * Rollup config for building the slim Swiper bundle.
 *
 * Why: Tree-shakes Swiper to only the 4 modules TVPG uses,
 * reducing JS from 154KB to ~40-50KB minified.
 *
 * @package TVPG
 * @since   1.5.0
 */
import resolve from '@rollup/plugin-node-resolve';
import terser from '@rollup/plugin-terser';
import postcss from 'rollup-plugin-postcss';
import cssnano from 'cssnano';

export default {
    input: 'assets/lib/swiper/swiper-slim.mjs',
    output: {
        file: 'assets/lib/swiper/swiper-slim.min.js',
        format: 'iife',
        name: 'SwiperBundle',
    },
    plugins: [
        resolve(),
        postcss({
            extract: 'swiper-slim.min.css',
            minimize: true,
            plugins: [cssnano()],
        }),
        terser(),
    ],
};
