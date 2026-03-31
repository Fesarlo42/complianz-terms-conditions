import gulp from 'gulp';
import concat from 'gulp-concat';
import cssuglify from 'gulp-uglifycss';
import jsuglify from 'gulp-uglify';
import gulpless from 'gulp-less';
import gulpautoprefixer from 'gulp-autoprefixer';
import mergeStream from 'merge-stream';

function lessTask() {
	const admin = gulp.src('./assets/css/admin.less')
		.pipe(gulpless())
		.pipe(gulpautoprefixer())
		.pipe(gulp.dest('./assets/css'))
		.pipe(cssuglify())
		.pipe(concat('admin.min.css'))
		.pipe(gulp.dest('./assets/css'));

	const wizard = gulp.src('./assets/css/wizard.less')
		.pipe(gulpless())
		.pipe(gulpautoprefixer())
		.pipe(gulp.dest('./assets/css'))
		.pipe(cssuglify())
		.pipe(concat('wizard.min.css'))
		.pipe(gulp.dest('./assets/css'));

	return mergeStream(admin, wizard);
}
export { lessTask as less };

function jsTask() {
	return gulp.src('assets/js/admin.js')
		.pipe(concat('admin.min.js'))
		.pipe(jsuglify())
		.pipe(gulp.dest('./assets/js'));
}
export { jsTask as js };

function defaultTask(cb) {
	gulp.watch('./assets/css/*.less', { ignoreInitial: false }, lessTask);
	gulp.watch('./assets/js/admin.js', { ignoreInitial: false }, jsTask);
	cb();
}
export default defaultTask;
